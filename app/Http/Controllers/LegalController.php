<?php

namespace App\Http\Controllers;

final class LegalController extends Controller
{
    public function privacy()
    {
        return view('legal.privacy');
    }

    public function support()
    {
        return view('legal.support', [
            'supportEmail' => (string) config('canovia.support_email', ''),
            'operatorName' => (string) config('canovia.operator_name', 'Canovia'),
        ]);
    }
}
