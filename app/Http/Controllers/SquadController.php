<?php

namespace App\Http\Controllers;

use App\Battle;
use App\GameClient;
use App\Outcome;
use App\RankerInterface;
use App\Squad;
use Cookie;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class SquadController extends Controller
{

    protected $client;
    protected $ranker;

    /**
     * SquadController constructor.
     * @param GameClient $client
     * @param RankerInterface $ranker
     */
    public function __construct(GameClient $client, RankerInterface $ranker)
    {
        $this->client = $client;
        $this->ranker = $ranker;
    }

    public function squadHistory(Request $request, $id)
    {
        try {
            $squad = Squad::findOrFail($id);
        } catch (ModelNotFoundException $e) {
            return view('squad.not_found');
        }

        $offensiveBattles = Battle::whereSquadId($squad->id)->get();
        $defensiveBattles = Battle::whereOpponentId($squad->id)->get();

        $battles = [];
        foreach ($offensiveBattles as $offensiveBattle) {
            $skill_before = $this->ranker->calculateScore($offensiveBattle->mu_before, $offensiveBattle->sigma_before);
            $opponent_skill_before = $this->ranker->calculateScore($offensiveBattle->opponent_mu_before, $offensiveBattle->opponent_sigma_before);
            $battles[$offensiveBattle->end_date] = [
                'score' => $offensiveBattle->score,
                'opponent_score' => $offensiveBattle->opponent_score,
                'opponent' => Squad::findOrNew($offensiveBattle->opponent_id),
                'skill_difference' => max(1, $opponent_skill_before) / max(1, $skill_before),
                'skill_change' => $offensiveBattle->skillChange,
            ];
        }
        foreach ($defensiveBattles as $defensiveBattle) {
            $skill_before = $this->ranker->calculateScore($defensiveBattle->opponent_mu_before, $defensiveBattle->opponent_sigma_before);
            $opponent_skill_before = $this->ranker->calculateScore($defensiveBattle->mu_before, $defensiveBattle->sigma_before);
            $battles[$defensiveBattle->end_date] = [
                'score' => $defensiveBattle->opponent_score,
                'opponent_score' => $defensiveBattle->score,
                'opponent' => Squad::findOrNew($defensiveBattle->squad_id),
                'skill_difference' => max(1, $opponent_skill_before) / max(1, $skill_before),
                'skill_change' => $defensiveBattle->opponentSkillChange,
            ];
        }

        krsort($battles);

        if ($request->wantsJson()) {
            return $battles;
        }

        return view('squad.history', compact(['battles', 'squad']));
    }

    public function squadMembers(Request $request, $id)
    {
        $squad = Squad::findOrFail($id);

        $members = $squad->members()->sortable(['xp' => 'desc'])->get();

        if ($request->wantsJson()) {
            return $members;
        }

        $stats = [
            'Donated' => 0,
            'Received' => 0,
            'HQ level' => 0,
            'Rep invested' => 0,
            'Base strength' => 0,
            'Medals' => 0,
            'Attacks won' => 0,
            'Defenses won' => 0,
        ];
        foreach ($members as $member) {
            $stats['Donated'] += $member->troopsDonated;
            $stats['Received'] += $member->troopsReceived;
            $stats['HQ level'] += $member->hqLevel;
            $stats['Rep invested'] += $member->reputationInvested;
            $stats['Base strength'] += $member->xp;
            $stats['Medals'] += $member->score;
            $stats['Attacks won'] += $member->attacksWon;
            $stats['Defenses won'] += $member->defensesWon;
        }

        return view('squad.members', compact(['members', 'squad', 'stats']));
    }

    public function squadPredict(Request $request, $id, $opponentId = null)
    {
        /** @var Squad $squad */
        $squad = Squad::findOrFail($id);

        // Get the opponent from the url if present.
        if ($opponentId) {
            $opponent = Squad::find($opponentId);
        } // Get the opponent from the search of present.
        elseif ($request->has('match')) {
            $searchTerm = $request->input('match');
            $results = Squad::whereDeleted(false)
                ->where('name', 'LIKE', '%' . $searchTerm . '%')
                ->orderByRaw('mu - (' . config('sod.sigma_multiplier') . '*sigma) desc')
                ->simplePaginate(20);
            if (count($results) === 1) {
                $opponent = $results->first();
                unset($results);
            }
        } elseif ($request->hasCookie('squad_id')) {
            $opponent = Squad::find($request->cookie('squad_id'));
        }

        // Add the match prediction.
        if (isset($opponent)) {
            Cookie::queue('squad_id', $opponent->id);

            if ($squad->skill > $opponent->skill) {
                $predictions = $this->winPrediction($squad, $opponent);
            } elseif ($squad->skill < $opponent->skill) {
                $predictions = $this->losePrediction($squad, $opponent);
            } else {
                $predictions = $this->drawPrediction($squad, $opponent);
            }
        }

        return view('squad.predict', compact(['squad', 'opponent', 'results', 'predictions']));
    }


    /**
     * @param Squad $squad
     * @param Squad $opponent
     *
     * @return array
     */
    protected function winPrediction(Squad $squad, Squad $opponent)
    {
        return [
          [
            'text' => 'I predict that ' . $squad->renderName() . ' will beat ' . $opponent->renderName() . ' with this result:',
            'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Win),
          ],
          [
            'text' => 'But if ' . $opponent->renderName() . ' beats ' . $squad->renderName() . ' the result will be:',
            'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Lose),
          ],
          [
            'text' => 'And in case of a non 45-45 tie the results will be:',
            'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Draw),
          ],
        ];
    }


    /**
     * @param Squad $squad
     * @param Squad $opponent
     * @param int $outcome
     * @return array
     */
    protected function createOutcomeTableData(Squad $squad, Squad $opponent, $outcome)
    {
        list($squadRating, $opponentRating) = $this->ranker->calculateSkillRatings($squad, $opponent, $outcome);
        $newSkill = $this->ranker->calculateScore($squadRating->getMean(), $squadRating->getStandardDeviation());
        $opponentNewSkill = $this->ranker->calculateScore($opponentRating->getMean(), $opponentRating->getStandardDeviation());

        // Deal with unranked squads.
        $newSquadRank = $this->calculateNewSquadRank($squad, $outcome, $newSkill);
        $newOpponentRank = $this->calculateNewSquadRank($opponent, 0 - $outcome, $opponentNewSkill);

        $data = [
            [
                'squad' => $squad->renderName(),
                'old_rank' => $squad->rank,
                'new_rank' => $newSquadRank,
                'change' => $newSkill - $squad->skill,
            ],
            [
                'squad' => $opponent->renderName(),
                'old_rank' => $opponent->rank,
                'new_rank' => $newOpponentRank,
                'change' => $opponentNewSkill - $opponent->skill,
            ],
        ];
        return $data;
    }


    /**
     * @param Squad $squad
     * @param int   $outcome
     * @param float $newSkill
     *
     * @return int
     */
    protected function calculateNewSquadRank(Squad $squad, $outcome, $newSkill)
    {
        $winsToGo = config('sod.win_threshold') - $squad->wins - max($outcome, 0);
        if ($winsToGo > 0) {
            $newSquadRank = $squad::formatUnranked($winsToGo, $newSkill);
        } else {
            $newSquadRank = $squad::calculateRankFromSkill($newSkill);
        }
        return $newSquadRank;
    }


    /**
     * @param Squad $squad
     * @param Squad $opponent
     * @return array
     */
    protected function losePrediction(Squad $squad, Squad $opponent)
    {
        return [
            [
                'text' => 'I predict that ' . $opponent->renderName() . ' will beat ' . $squad->renderName() . ' with this result:',
                'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Lose),
            ],
            [
                'text' => 'But if ' . $squad->renderName() . ' beats ' . $opponent->renderName() . ' the result will be:',
                'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Win),
            ],
          [
            'text'     => 'And in case of a non 45-45 tie the results will be:',
                'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Draw),
            ],
        ];
    }


    /**
     * @param Squad $squad
     * @param Squad $opponent
     * @return array
     */
    protected function drawPrediction(Squad $squad, Squad $opponent)
    {
        return [
            [
                'text' => 'I predict a tie between ' . $squad->renderName() . ' and ' . $opponent->renderName(),
                'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Lose),
            ],
            [
                'text' => 'But if ' . $squad->renderName() . ' beats ' . $opponent->renderName() . ' the result will be:',
                'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Lose),
            ],
            [
                'text' => 'And if ' . $opponent->renderName() . ' beats ' . $squad->renderName() . ' the result will be:',
                'data' => $this->createOutcomeTableData($squad, $opponent, Outcome::Draw),
            ],
        ];
    }
}
