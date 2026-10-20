<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * سحب طلّة — صفحةٌ مؤقّتة لفعاليةٍ واحدة، تُحذف بعدها.
 *
 * لا جدول ولا هجرة: المسجّلون في ملفٍّ واحد على القرص، فالحذف حذفُ ملفّات
 * لا هجرةَ عكسية. وكلّ كتابةٍ تحت قفل، فتسجيلان في اللحظة نفسها لا يمحو
 * أحدُهما الآخر.
 */
class TallaController extends Controller
{
    private const FILE = 'talla.json';

    public function enter(Request $request): View
    {
        $data = $this->read();
        $step = match (true) {
            $request->session()->has('talla.done') => 'done',
            $request->session()->has('talla.name') => 'question',
            default => 'name',
        };

        return view('talla.enter', [
            'step' => $step,
            'question' => $data['question'],
            'name' => $request->session()->get('talla.name'),
        ]);
    }

    public function name(Request $request): RedirectResponse
    {
        $name = trim((string) $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ])['name']);

        $request->session()->put('talla.name', $name);

        return redirect()->route('talla');
    }

    public function answer(Request $request): RedirectResponse
    {
        $name = $request->session()->get('talla.name');
        if (! $name || $request->session()->has('talla.done')) {
            return redirect()->route('talla');
        }

        $answer = trim((string) $request->validate([
            'answer' => ['required', 'string', 'max:500'],
        ])['answer']);

        $this->write(function (array $data) use ($name, $answer) {
            $data['entries'][] = [
                'name' => $name,
                'answer' => $answer,
                'at' => now()->toIso8601String(),
            ];

            return $data;
        });

        $request->session()->put('talla.done', true);

        return redirect()->route('talla');
    }

    public function board(): View
    {
        return view('talla.draw', ['data' => $this->read()]);
    }

    public function question(Request $request): RedirectResponse
    {
        $question = trim((string) $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ])['question']);

        $this->write(fn (array $data) => ['question' => $question] + $data);

        return redirect()->route('talla.board');
    }

    public function draw(): JsonResponse
    {
        $winner = null;
        $this->write(function (array $data) use (&$winner) {
            if ($data['entries'] === []) {
                return $data;
            }
            $winner = $data['entries'][random_int(0, count($data['entries']) - 1)];
            $data['winner'] = $winner;

            return $data;
        });

        return response()->json(['winner' => $winner]);
    }

    public function reset(): RedirectResponse
    {
        $this->write(fn (array $data) => ['entries' => [], 'winner' => null] + $data);

        return redirect()->route('talla.board');
    }

    private function read(): array
    {
        $raw = Storage::disk('local')->exists(self::FILE)
            ? json_decode(Storage::disk('local')->get(self::FILE), true)
            : null;

        return (is_array($raw) ? $raw : []) + [
            'question' => 'ما هو سؤالك؟',
            'entries' => [],
            'winner' => null,
        ];
    }

    private function write(callable $change): void
    {
        $path = Storage::disk('local')->path(self::FILE);
        @mkdir(dirname($path), 0775, true);

        $handle = fopen($path, 'c+');
        flock($handle, LOCK_EX);
        try {
            $current = json_decode(stream_get_contents($handle), true);
            $data = $change((is_array($current) ? $current : []) + [
                'question' => 'ما هو سؤالك؟',
                'entries' => [],
                'winner' => null,
            ]);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
