<?php

namespace Modules\Products\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Products\Http\Requests\ListProductsRequest;
use Modules\Products\Http\Requests\StoreProductRequest;
use Modules\Products\Http\Requests\UpdateProductRequest;
use Modules\Products\Http\Requests\UploadProductImagesRequest;
use Modules\Products\Http\Resources\ProductImageResource;
use Modules\Products\Http\Resources\ProductResource;
use Modules\Products\Models\Product;
use Modules\Products\Services\ProductService;
use Modules\Shared\Helpers\ApiResponse;

class ProductController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private ProductService $products
    ) {}

    // GET /api/v1/products
    public function index(ListProductsRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            $this->products->paginate($request->validated()),
            ProductResource::class,
        );
    }

    // GET /api/v1/products/{product}
    public function show(Product $product): JsonResponse
    {
        return ApiResponse::ok(new ProductResource($this->products->find($product->id)));
    }

    // POST /api/v1/products  (multipart: images are uploaded here, never on PATCH)
    public function store(StoreProductRequest $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->products->create(
            $request->validated(),
            $request->file('images', []),
        );

        return ApiResponse::created(
            new ProductResource($product),
            url('/api/v1/products/'.$product->id),
        );
    }

    // PATCH /api/v1/products/{product}
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        return ApiResponse::ok(
            new ProductResource($this->products->update($product, $request->validated())),
        );
    }

    // DELETE /api/v1/products/{product}  (soft delete)
    public function destroy(Product $product): Response
    {
        $this->authorize('delete', $product);

        $this->products->delete($product);

        return ApiResponse::noContent();
    }

    // POST /api/v1/products/{product}/images
    public function storeImages(UploadProductImagesRequest $request, Product $product): JsonResponse
    {
        $this->authorize('uploadImage', $product);

        $images = $this->products->addImages($product, $request->file('images', []));

        return ApiResponse::created([
            'images' => ProductImageResource::collection($images),
        ]);
    }
}
