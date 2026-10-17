package om.abaad.pos.printing

import java.util.concurrent.ArrayBlockingQueue
import java.util.concurrent.RejectedExecutionException
import java.util.concurrent.ThreadFactory
import java.util.concurrent.ThreadPoolExecutor
import java.util.concurrent.TimeUnit

/**
 * طابورُ الطباعة: مهمّةٌ واحدة تعمل، وخلفها `maxWaiting` تنتظر على الأكثر (2)،
 * وما زاد يُرفض فورًا — فيردّ الجسرُ `QUEUE_FULL` ولا يتراكم ضغطٌ لا ينتهي.
 */
class PrintJobQueue(name: String, maxWaiting: Int = 2) {

    private val executor: ThreadPoolExecutor

    init {
        require(maxWaiting >= 1) { "maxWaiting must be at least 1" }
        executor = ThreadPoolExecutor(
            1,
            1,
            0L,
            TimeUnit.MILLISECONDS,
            ArrayBlockingQueue<Runnable>(maxWaiting),
            ThreadFactory { runnable -> Thread(runnable, name).apply { isDaemon = true } },
        )
    }

    /** false = الطابورُ ممتلئ (أو أُغلق) */
    fun submit(job: () -> Unit): Boolean =
        try {
            executor.execute(
                Runnable {
                    // المهمّةُ تملك ردَّها وسجلَّها؛ وما أفلت منها لا يُسقط التطبيقَ كلَّه
                    try {
                        job()
                    } catch (e: Throwable) {
                        // لا شيء
                    }
                },
            )
            true
        } catch (e: RejectedExecutionException) {
            false
        }

    fun shutdown() {
        executor.shutdownNow()
    }
}
