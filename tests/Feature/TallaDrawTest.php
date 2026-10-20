<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** سحب طلّة — مؤقّت، يُحذف معه */
class TallaDrawTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_registers_answers_and_is_drawn(): void
    {
        Storage::fake('local');

        $this->get('/talla')->assertOk()->assertSee('الاسم');
        $this->post('/talla/name', ['name' => 'سالم'])->assertRedirect('/talla');
        $this->get('/talla')->assertSee('ما هو سؤالك؟');
        $this->post('/talla/answer', ['answer' => 'مسقط'])->assertRedirect('/talla');
        $this->get('/talla')->assertSee('تمّ تسجيلك');
        // مرّةٌ واحدة لكلّ جهاز
        $this->post('/talla/answer', ['answer' => 'ثانية']);

        $admin = User::create([
            'name' => 'مدير', 'email' => 'a@example.com', 'password' => bcrypt('password'),
            'role' => 'super_admin', 'status' => 'نشط',
        ]);

        $this->actingAs($admin)->get('/talla/draw')->assertOk()->assertSee('سالم')->assertSee('مسقط');
        $this->actingAs($admin)->postJson('/talla/draw/pick')->assertJsonPath('winner.name', 'سالم');
        $this->actingAs($admin)->post('/talla/draw/question', ['question' => 'عاصمة عمان؟']);
        $this->actingAs($admin)->post('/talla/draw/reset');

        $data = json_decode(Storage::disk('local')->get('talla.json'), true);
        $this->assertSame('عاصمة عمان؟', $data['question']);
        $this->assertSame([], $data['entries']);
    }

    public function test_the_board_is_closed_to_visitors(): void
    {
        $this->get('/talla/draw')->assertRedirect();
        $this->post('/talla/draw/pick')->assertRedirect();
    }
}
