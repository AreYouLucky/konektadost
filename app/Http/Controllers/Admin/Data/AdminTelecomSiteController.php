<?php

namespace App\Http\Controllers\Admin\Data;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TelecomSite;

class AdminTelecomSiteController extends Controller
{
    public function index(){
        return Inertia::render('admin/telecom-site/telecom-site');
    }


    public function store(Request $req){

    }
}
