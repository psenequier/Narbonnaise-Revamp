<?php

use App\Models\Lignee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->lignee = Lignee::create(['nom' => 'Lignée A', 'couleur' => '#8BBF7E']);
    Lignee::create(['nom' => 'Lignée B', 'couleur' => '#F2B5C4']);
});

it('permet à un admin de renommer une lignée, avec une trace au journal, sans champ couleur', function () {
    $this->actingAs($this->admin)->get('/admin/lignees/modifier')->assertOk()->assertSee('Lignée A')->assertDontSee('name="couleur"', false);

    $this->actingAs($this->admin)->put("/admin/lignees/{$this->lignee->id}", ['nom' => ' Branche prairie ', 'couleur' => '#1f4d3a'])
        ->assertRedirect()->assertSessionHas('status');

    expect($this->lignee->fresh()->only('nom', 'couleur'))->toBe(['nom' => 'Branche prairie', 'couleur' => '#8BBF7E'])
        ->and(DB::table('journal')->where('action', 'lignee.modifiee')->exists())->toBeTrue();
});

it('refuse un nom déjà pris', function () {
    $this->actingAs($this->admin)->put("/admin/lignees/{$this->lignee->id}", ['nom' => 'Lignée B'])
        ->assertSessionHasErrors('nom', null, 'lignee'.$this->lignee->id);

    expect($this->lignee->fresh()->nom)->toBe('Lignée A');
});

it('ne sert plus de feuille de couleurs de lignée', function () {
    $this->actingAs($this->admin)->get('/lignees.css')->assertNotFound();
});

it('refuse la page et la modification à un membre (403)', function () {
    $membre = User::factory()->create();
    $this->actingAs($membre)->get('/admin/lignees/modifier')->assertForbidden();
    $this->actingAs($membre)->put("/admin/lignees/{$this->lignee->id}", ['nom' => 'X'])->assertForbidden();
});

it('ne recrée pas les lignées de LIGNEES une fois renommées', function () {
    $this->lignee->update(['nom' => 'Branche prairie']);
    config(['chamrousse.lignees' => 'Lignée A:#8BBF7E,Lignée B:#F2B5C4']);

    $this->seed(DatabaseSeeder::class);

    expect(Lignee::count())->toBe(2)->and(Lignee::where('nom', 'Lignée A')->exists())->toBeFalse();
});

it('remplit les lignées depuis LIGNEES quand la table est vide, en ignorant une couleur invalide', function () {
    DB::table('lignees')->delete();

    expect(Lignee::remplirSiVide('Nord:#112233, Sud:#445566,Est:bleu'))->toBe(2)
        ->and(Lignee::orderBy('id')->pluck('couleur', 'nom')->all())->toBe(['Nord' => '#112233', 'Sud' => '#445566']);
});
