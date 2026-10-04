<?php

test('there is no public sign-up page', function () {
    $this->get('/register')->assertNotFound();
});
