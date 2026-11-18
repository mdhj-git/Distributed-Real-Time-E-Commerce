<?php

namespace Modules\Web\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

/**
 * One method per page, and nothing else: a page is a shell. No model and no data is passed to any view
 * (docs/PROJECT_RULES.md, section 13); the page's JavaScript loads everything from /api/v1.
 */
class PageController extends Controller
{
    public function home(): View
    {
        return view('web::pages.home');
    }

    public function register(): View
    {
        return view('web::pages.register');
    }

    public function login(): View
    {
        return view('web::pages.login');
    }

    public function account(): View
    {
        return view('web::pages.account');
    }

    public function products(): View
    {
        return view('web::pages.products');
    }

    public function product(): View
    {
        return view('web::pages.product');
    }

    public function orders(): View
    {
        return view('web::pages.orders');
    }

    public function ordersNew(): View
    {
        return view('web::pages.orders-new');
    }

    public function order(): View
    {
        return view('web::pages.order');
    }

    public function notifications(): View
    {
        return view('web::pages.notifications');
    }

    public function adminOrders(): View
    {
        return view('web::pages.admin-orders');
    }

    public function adminProductNew(): View
    {
        return view('web::pages.admin-product-new');
    }

    public function adminProductEdit(): View
    {
        return view('web::pages.admin-product-edit');
    }
}
