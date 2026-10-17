package om.abaad.pos.printing

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

class PrintJobQueueTest {

    @Test(timeout = 15_000)
    fun oneRunsTwoWaitAndTheFourthIsQueueFull() {
        val queue = PrintJobQueue("test-printer")
        val started = CountDownLatch(1)
        val release = CountDownLatch(1)
        val finished = CountDownLatch(3)
        try {
            assertTrue(
                queue.submit {
                    started.countDown()
                    release.await()
                    finished.countDown()
                },
            )
            assertTrue(started.await(5, TimeUnit.SECONDS))
            assertTrue(queue.submit { finished.countDown() })
            assertTrue(queue.submit { finished.countDown() })
            assertFalse("the 4th concurrent job must be refused", queue.submit { finished.countDown() })

            release.countDown()
            assertTrue(finished.await(5, TimeUnit.SECONDS))

            // وتعود السعةُ بعد أن تنتهي
            val again = CountDownLatch(1)
            assertTrue(queue.submit { again.countDown() })
            assertTrue(again.await(5, TimeUnit.SECONDS))
        } finally {
            release.countDown()
            queue.shutdown()
        }
    }

    @Test(timeout = 15_000)
    fun aThrowingJobDoesNotStopTheQueue() {
        val queue = PrintJobQueue("test-printer-throws")
        val after = CountDownLatch(1)
        try {
            assertTrue(queue.submit { throw IllegalStateException("boom") })
            assertTrue(queue.submit { after.countDown() })
            assertTrue(after.await(5, TimeUnit.SECONDS))
        } finally {
            queue.shutdown()
        }
    }
}
