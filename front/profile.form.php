<?php

use GlpiPlugin\Storefront\Profile;

// CSRF-токен POST-запроса проверяет и гасит само ядро GLPI 11,
// повторная проверка здесь всегда падала бы на уже погашенном токене.
Session::checkRight('profile', UPDATE);

if (isset($_POST['update'], $_POST['profiles_id'])) {
    if (Profile::save((int) $_POST['profiles_id'], $_POST)) {
        Session::addMessageAfterRedirect(__('Права магазина сохранены.', 'storefront'), false, INFO);
    }
}

Html::back();
