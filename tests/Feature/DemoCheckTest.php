<?php

it('refuses demo preflight on a normal application without querying or changing its data', function () {
    $this->artisan('demo:check')
        ->expectsOutput('Commande reservee a APP_ENV=demo. Aucune modification effectuee.')
        ->assertFailed();
});
