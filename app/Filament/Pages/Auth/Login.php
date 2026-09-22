<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * Вход в админку: «Запомнить меня» — переключатель вместо стандартного чекбокса.
 */
class Login extends BaseLogin
{
    protected function getRememberFormComponent(): Component
    {
        return Toggle::make('remember')
            ->label(__('filament-panels::pages/auth/login.form.remember.label'));
    }
}
