<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * البوّابةُ تقرأ نجاحَ CI من التشغيل ووظائفه، لا من فهرس البحث عند GitHub.
 *
 * ═══ العطب ═══
 *
 * دُمج #233 بعد نجاح CI كاملًا، فرفضت «Main Gate» النشر: «لا تشغيلَ CI ناجحًا».
 * كانت تسأل GitHub بمرشِّح `status=success`، وهذا المرشِّحُ يقرأ فهرسَ بحثٍ بقي
 * يقول عن التشغيل «in_progress» ثلاثَ ساعاتٍ ونصفًا بعد اكتماله ناجحًا — والتشغيلُ
 * نفسُه يقول `completed / success`.
 *
 * ═══ وما يُحرس هنا ═══
 *
 * يُشغَّل سكربتُ البوّابة الحقيقيّ على مستودع git مؤقّت، وأمامه `gh` مزيّفٌ يجيب
 * أسئلته من ملفّات — ويجيب سؤالَ المرشِّح من «فهرسٍ» متأخّر:
 *
 *   - التشغيلُ الناجح يُوجد والفهرسُ متأخّر، والنشرُ يُقبل.
 *   - وما لم ينجح (فاشلًا أو جاريًا) يُرفض ولو كان إثباتُه سليمًا.
 *   - ونجاحُ التشغيل لا يكفي: كلُّ وظيفةٍ فيه ناجحة، والمطلوبةُ كلُّها حاضرة.
 *   - والإثباتُ لهذه المحاولة بعينها، ولهذا الطلب والرأس والتشغيل والشجرة.
 *   - والبوّابةُ تقرأ ولا تكتب.
 *   - وقائمةُ الوظائف المطلوبة فيها هي ما تعرّفه `ci.yml`.
 */
class TheGateReadsSuccessFromTheRunNotFromGitHubsIndexTest extends TestCase
{
    private const PR = 233;

    private const HEAD = '34dbdbca1267ee40d681a44cd06eb185d6f38d7d';

    private const OTHER_HEAD = 'aec2de6ec7a5770407abe91110b79acc827f0de9';

    /** ما تعرّفه ci.yml لطلبٍ — يُقارَن بقائمة البوّابة في الاختبار الأخير */
    private const JOBS = [
        'الاختبارات (SQLite)',
        'الاختبارات (PostgreSQL 0/3)',
        'الاختبارات (PostgreSQL 1/3)',
        'الاختبارات (PostgreSQL 2/3)',
        'الواجهة (أنواع + اختبارات + بناء)',
        'إثبات الشجرة المختبَرة',
    ];

    private string $dir;

    private string $fake;

    private string $mainSha;

    private string $mainTree;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['git', 'jq', 'unzip', 'zip', 'bash'] as $tool) {
            $this->assertTrue($this->onPath($tool), "{$tool} غير موجود — البوّابةُ تحتاجه");
        }

        $this->dir = sys_get_temp_dir().'/abaad-gate-'.bin2hex(random_bytes(6));
        $this->fake = $this->dir.'/fake';
        File::ensureDirectoryExists($this->dir.'/repo');
        File::ensureDirectoryExists($this->fake.'/bin');

        // main: إيداعٌ واحد، وشجرتُه هي ما يجب أن يطابقه الإثبات
        file_put_contents($this->dir.'/repo/a.txt', "main\n");
        $git = ['git', '-C', $this->dir.'/repo', '-c', 'user.name=gate', '-c', 'user.email=gate@example.invalid', '-c', 'commit.gpgsign=false'];
        $this->mustRun(['git', 'init', '-q', $this->dir.'/repo']);
        $this->mustRun([...$git, 'add', 'a.txt']);
        $this->mustRun([...$git, 'commit', '-q', '-m', 'main']);
        $this->mainSha = trim($this->mustRun([...$git, 'rev-parse', 'HEAD'])->getOutput());
        $this->mainTree = trim($this->mustRun([...$git, 'rev-parse', 'HEAD^{tree}'])->getOutput());

        $this->fixture('pulls.json', [[
            'number' => self::PR,
            'merged_at' => '2026-11-10T15:15:05Z',
            'base' => ['ref' => 'main'],
            'merge_commit_sha' => $this->mainSha,
            'head' => ['sha' => self::HEAD],
        ]]);
        $this->index([]); // الفهرسُ متأخّر في كلّ حالة: لا شيء تحت status=success

        file_put_contents($this->fake.'/bin/gh', <<<'SH'
#!/usr/bin/env bash
# gh مزيّف: يجيب أسئلةَ البوّابة من ملفّات $FAKE، ويسجّل كلَّ سؤال.
{ printf '%s' "$*" | tr '\n' ' '; echo; } >> "$FAKE/calls.log"
jqexpr=""; prev=""; path=""; filtered=0
for a in "$@"; do
    [ "$prev" = "--jq" ] && jqexpr="$a"
    case "$a" in repos/*) path="$a" ;; status=*) filtered=1 ;; esac
    prev="$a"
done
out() { [ -f "$FAKE/$1" ] || { echo "no $1" >&2; exit 1; }; if [ -n "$jqexpr" ]; then jq -r "$jqexpr" "$FAKE/$1"; else cat "$FAKE/$1"; fi; }
case "$path" in
    */pulls) out pulls.json ;;
    */actions/workflows/ci.yml/runs) if [ "$filtered" = 1 ]; then out index.json; else out runs.json; fi ;;
    */actions/runs/*/jobs) id="${path%/jobs}"; out "jobs-${id##*/}.json" ;;
    */actions/runs/*/artifacts) id="${path%/artifacts}"; out "artifacts-${id##*/}.json" ;;
    */actions/artifacts/*/zip) id="${path%/zip}"; cat "$FAKE/proof-${id##*/}.zip" ;;
    *) echo "unexpected gh call: $*" >&2; exit 1 ;;
esac
SH);
        chmod($this->fake.'/bin/gh', 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    // ─── المتأخّر يُقبل ───

    public function test_a_passed_run_is_found_while_githubs_index_still_says_in_progress(): void
    {
        $this->passedRun(111);

        $gate = $this->gate();

        $this->assertSame(0, $gate->getExitCode(), $gate->getOutput().$gate->getErrorOutput());
        $this->assertStringContainsString('✓ شجرة main '.$this->mainTree, $gate->getOutput());
        $this->assertStringContainsString('التشغيل 111', $gate->getOutput());
        $this->assertStringContainsString('6 وظائفَ مطلوبة ناجحة', $gate->getOutput());
    }

    public function test_a_rerun_that_passed_is_proven_by_its_own_attempts_proof(): void
    {
        $this->runs(['id' => 121, 'run_attempt' => 2]);
        $this->jobs(121);
        $this->proof(121, $this->mainTree, attempt: 1);
        $this->proof(121, $this->mainTree, attempt: 2);

        $gate = $this->gate();

        $this->assertSame(0, $gate->getExitCode(), $gate->getOutput());
        $this->assertStringContainsString('المحاولة 2', $gate->getOutput());
    }

    // ─── ما لم ينجح يُرفض ───

    public function test_a_run_that_did_not_pass_is_refused_even_with_a_sound_proof(): void
    {
        // فاشلٌ وجارٍ — ولكلٍّ إثباتٌ يطابق main ووظائفُ ناجحة، فلا يردّهما إلّا أنّهما لم ينجحا
        $this->runs(
            ['id' => 211, 'status' => 'completed', 'conclusion' => 'failure'],
            ['id' => 212, 'status' => 'in_progress', 'conclusion' => null],
        );
        foreach ([211, 212] as $run) {
            $this->jobs($run);
            $this->proof($run, $this->mainTree);
        }

        $this->assertRefused('لا تشغيلَ CI ناجحًا');
    }

    /** @return array<string, array{string, string|null}> */
    public static function jobsThatDidNotPass(): array
    {
        return [
            'فاشلة' => ['completed', 'failure'],
            'متخطّاة' => ['completed', 'skipped'],
            'ملغاة' => ['completed', 'cancelled'],
            'جارية' => ['in_progress', null],
            'منتظرة' => ['queued', null],
        ];
    }

    /** تشغيلٌ يقول «نجح» ووظيفةٌ فيه لم تكتمل ناجحة — لا يُثبت شيئًا */
    #[DataProvider('jobsThatDidNotPass')]
    public function test_a_passed_run_with_a_job_that_did_not_pass_is_refused(string $status, ?string $conclusion): void
    {
        $this->runs(['id' => 221]);
        $this->jobs(221, ['الاختبارات (PostgreSQL 1/3)' => [$status, $conclusion]]);
        $this->proof(221, $this->mainTree);

        $this->assertRefused('وظائفُ لم تكتمل ناجحة');
    }

    public function test_a_passed_run_missing_a_required_job_is_refused(): void
    {
        $this->runs(['id' => 231]);
        $this->jobs(231, ['الاختبارات (PostgreSQL 2/3)' => null]);
        $this->proof(231, $this->mainTree);

        $this->assertRefused('وظائفُ مطلوبة غائبة: الاختبارات (PostgreSQL 2/3)');
    }

    public function test_a_passed_run_with_no_jobs_is_refused(): void
    {
        $this->runs(['id' => 232]);
        $this->fixture('jobs-232.json', ['total_count' => 0, 'jobs' => []]);
        $this->proof(232, $this->mainTree);

        $this->assertRefused('لا وظائفَ له');
    }

    // ─── والإثباتُ يطابق ما سُئل عنه ───

    public function test_a_proof_from_an_earlier_attempt_does_not_prove_the_rerun(): void
    {
        // المحاولةُ الثانية هي التي نجحت، والمرفوعُ إثباتُ الأولى وحده
        $this->runs(['id' => 241, 'run_attempt' => 2]);
        $this->jobs(241);
        $this->proof(241, $this->mainTree, attempt: 1);

        $this->assertRefused('لا إثباتَ مرفوعًا لمحاولته 2');
    }

    public function test_a_proof_whose_content_names_another_attempt_is_refused(): void
    {
        $this->runs(['id' => 242, 'run_attempt' => 2]);
        $this->jobs(242);
        $this->proof(242, $this->mainTree, attempt: 2, content: ['workflow_run_attempt' => 1]);

        $this->assertRefused('الإثبات لمحاولةٍ أخرى');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function proofsForSomethingElse(): array
    {
        return [
            'طلبٌ آخر' => [['pr_number' => 232], 'الإثبات لطلبٍ آخر'],
            'رأسٌ آخر' => [['head_sha' => self::OTHER_HEAD], 'رأسُ الإثبات'],
            'تشغيلٌ آخر' => [['workflow_run_id' => 999], 'الإثبات يذكر تشغيلًا آخر'],
        ];
    }

    /** @param  array<string, mixed>  $content */
    #[DataProvider('proofsForSomethingElse')]
    public function test_a_proof_for_something_else_is_refused(array $content, string $why): void
    {
        $this->runs(['id' => 251]);
        $this->jobs(251);
        $this->proof(251, $this->mainTree, content: $content);

        $this->assertRefused($why);
    }

    public function test_a_passed_run_whose_tree_is_not_mains_is_refused(): void
    {
        $this->runs(['id' => 311]);
        $this->jobs(311);
        $this->proof(311, str_repeat('ab', 20));

        $this->assertRefused('≠ شجرة main');
    }

    /** وإن ردّ GitHub تشغيلًا لرأسٍ آخر أو حدثٍ آخر أو ملفٍّ آخر — يُطرح */
    #[DataProvider('runsForSomethingElse')]
    public function test_a_run_for_another_head_event_or_workflow_is_not_counted(string $field, string $value): void
    {
        $this->runs(['id' => 321, $field => $value]);
        $this->jobs(321);
        $this->proof(321, $this->mainTree);

        $this->assertRefused('لا تشغيلَ CI ناجحًا');
    }

    /** @return array<string, array{string, string}> */
    public static function runsForSomethingElse(): array
    {
        return [
            'رأسٌ آخر' => ['head_sha', self::OTHER_HEAD],
            'حدثٌ آخر' => ['event', 'workflow_dispatch'],
            'ملفٌّ آخر' => ['path', '.github/workflows/mobile.yml'],
        ];
    }

    // ─── قراءةٌ فقط ───

    public function test_the_gate_only_reads_from_github(): void
    {
        $this->passedRun(111);

        $this->assertSame(0, $this->gate()->getExitCode());

        $calls = array_filter(explode("\n", (string) file_get_contents($this->fake.'/calls.log')));
        $this->assertNotEmpty($calls);
        foreach ($calls as $call) {
            $this->assertStringStartsWith('api ', $call);
            $this->assertDoesNotMatchRegularExpression('/-X\s*(POST|PUT|PATCH|DELETE)|--method\s*(POST|PUT|PATCH|DELETE)|(^|\s)-(F|f)\s*\S+=@/i', $call);
        }
        $this->assertNotEmpty(preg_grep('#/actions/runs/111/jobs#', $calls), 'لم تُسأل الوظائف');
    }

    // ─── والقائمةُ هي ما تعرّفه ci.yml ───

    public function test_the_gates_required_jobs_are_exactly_the_jobs_ci_runs_for_a_pull_request(): void
    {
        $script = (string) file_get_contents(base_path('.github/scripts/main-gate-verify.sh'));
        $this->assertSame(1, preg_match('/^REQUIRED_JOBS=\(\n(.*?)\n\)$/msu', $script, $m), 'قائمةُ الوظائف المطلوبة غائبة');
        preg_match_all('/^\s+"([^"]+)"$/mu', $m[1], $listed);

        $ci = (string) file_get_contents(base_path('.github/workflows/ci.yml'));
        preg_match_all('/^  [a-z][a-z0-9_-]*:\n((?:(?:    .*|)\n)*)/mu', $ci, $jobs);
        $defined = [];
        foreach ($jobs[1] as $body) {
            if (! preg_match('/^    name: (.+)$/mu', $body, $name)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression("/^    if: (?!github\\.event_name == 'pull_request'$)/mu", $body, "{$name[1]}: شرطٌ قد يتخطّاها في طلب");
            if (preg_match('/\$\{\{ matrix\.(\w+) \}\}/', $name[1], $var)) {
                $this->assertSame(1, preg_match('/^\s+'.$var[1].': \[([^\]]+)\]$/mu', $body, $values));
                foreach (array_map('trim', explode(',', $values[1])) as $v) {
                    $defined[] = str_replace('${{ matrix.'.$var[1].' }}', $v, $name[1]);
                }
            } else {
                $defined[] = $name[1];
            }
        }

        sort($defined);
        $listedSorted = $listed[1];
        sort($listedSorted);
        $this->assertSame($defined, $listedSorted, 'وظائفُ ci.yml وقائمةُ البوّابة تختلفان');

        $expected = self::JOBS;
        sort($expected);
        $this->assertSame($expected, $listedSorted);
    }

    // ─── أدوات ───

    private function assertRefused(string $why): void
    {
        $gate = $this->gate();

        $this->assertSame(1, $gate->getExitCode(), $gate->getOutput());
        $this->assertStringContainsString('Main commit has no matching successful PR CI proof', $gate->getOutput());
        $this->assertStringContainsString($why, $gate->getOutput());
        $this->assertStringNotContainsString('✓', $gate->getOutput());
    }

    private function gate(): Process
    {
        $p = new Process(
            ['bash', base_path('.github/scripts/main-gate-verify.sh')],
            $this->dir.'/repo',
            [
                'PATH' => $this->fake.'/bin:'.getenv('PATH'),
                'FAKE' => $this->fake,
                'GH_REPO' => 'abaadapp/abaad.Saas',
                'MAIN_SHA' => $this->mainSha,
                'GITHUB_STEP_SUMMARY' => $this->dir.'/summary.md',
            ],
        );
        $p->setTimeout(60);
        $p->run();

        return $p;
    }

    private function passedRun(int $run): void
    {
        $this->runs(['id' => $run]);
        $this->jobs($run);
        $this->proof($run, $this->mainTree);
    }

    /** @param  array<string, mixed>  ...$runs */
    private function runs(array ...$runs): void
    {
        $at = 0;
        $this->fixture('runs.json', ['total_count' => count($runs), 'workflow_runs' => array_map(
            fn (array $r) => $r + [
                'status' => 'completed',
                'conclusion' => 'success',
                'run_attempt' => 1,
                'event' => 'pull_request',
                'path' => '.github/workflows/ci.yml',
                'head_sha' => self::HEAD,
                'created_at' => sprintf('2026-11-10T11:%02d:00Z', 30 + $at++),
            ],
            $runs,
        )]);
    }

    /**
     * وظائفُ المحاولة الأخيرة: الستُّ ناجحةً، إلّا ما غُيّر — null يحذف الوظيفة.
     *
     * @param  array<string, array{string, string|null}|null>  $changes
     */
    private function jobs(int $run, array $changes = []): void
    {
        $jobs = [];
        foreach (self::JOBS as $i => $name) {
            if (array_key_exists($name, $changes) && $changes[$name] === null) {
                continue;
            }
            [$status, $conclusion] = $changes[$name] ?? ['completed', 'success'];
            $jobs[] = ['id' => $run * 100 + $i, 'name' => $name, 'status' => $status, 'conclusion' => $conclusion];
        }
        $this->fixture('jobs-'.$run.'.json', ['total_count' => count($jobs), 'jobs' => $jobs]);
    }

    /** @param  list<array<string, mixed>>  $runs  ما يردّه مرشِّح status=success */
    private function index(array $runs): void
    {
        $this->fixture('index.json', ['total_count' => count($runs), 'workflow_runs' => $runs]);
    }

    /** @param  array<string, mixed>  $content */
    private function proof(int $run, string $tree, int $attempt = 1, array $content = []): void
    {
        $artifact = $run * 10 + $attempt;
        $listFile = $this->fake.'/artifacts-'.$run.'.json';
        $list = is_file($listFile) ? json_decode((string) file_get_contents($listFile), true) : ['artifacts' => []];
        $list['artifacts'][] = ['id' => $artifact, 'name' => 'pr-proof-'.self::PR.'-'.$run.'-'.$attempt, 'expired' => false];
        $this->fixture('artifacts-'.$run.'.json', $list);

        $src = $this->fake.'/src-'.$artifact;
        File::ensureDirectoryExists($src);
        file_put_contents($src.'/pr-proof.json', json_encode($content + [
            'pr_number' => self::PR,
            'head_sha' => self::HEAD,
            'workflow_run_id' => $run,
            'workflow_run_attempt' => $attempt,
            'tested_tree_sha' => $tree,
        ]));
        $this->mustRun(['zip', '-q', '-j', $this->fake.'/proof-'.$artifact.'.zip', $src.'/pr-proof.json']);
    }

    private function fixture(string $file, array $data): void
    {
        file_put_contents($this->fake.'/'.$file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function onPath(string $tool): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir.'/'.$tool)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $cmd */
    private function mustRun(array $cmd): Process
    {
        $p = new Process($cmd);
        $p->setTimeout(60);
        $p->run();
        $this->assertTrue($p->isSuccessful(), implode(' ', $cmd).': '.$p->getErrorOutput());

        return $p;
    }
}
