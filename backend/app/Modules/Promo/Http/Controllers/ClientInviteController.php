<?php

namespace App\Modules\Promo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Promo\Services\ReferralService;
use Illuminate\Http\Request;

/** CL-13: personal invite link, terms of the program, invited friends and rewards (DEC-42). */
class ClientInviteController extends Controller
{
    public function show(Request $request, ReferralService $referrals)
    {
        return response()->json(['data' => $referrals->overview($request->user())]);
    }
}
