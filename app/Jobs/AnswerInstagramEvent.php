<?php

namespace App\Jobs;

use App\Models\InstagramEvent;
use App\Support\InstagramAutomations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ‏‏يردّ على حدثٍ واحد من إنستقرام — خارج طلب Meta، فيُجاب الإشعارُ فورًا مهما كثرت التعليقات.
 *
 * ومحاولةٌ واحدة: ما أُخذ لا يُؤخذ ثانيةً (`processed_at`)، وما أُجّل تلتقطه الجولةُ (`instagram:answer-pending`).
 */
class AnswerInstagramEvent implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = InstagramEvent::find($this->eventId);

        if ($event !== null) {
            InstagramAutomations::answer($event);
        }
    }
}
