<?php

namespace App\Controllers;

use App\Models\FinishingType;
use App\Models\Packing;
use App\Models\ProductMaster;
use App\Models\Stuffing;
use App\Support\ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class CatalogController
{
    public function products(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $keyword = trim($params['keyword'] ?? '');

        $query = ProductMaster::with(['materials.material'])
            ->where('is_active', true);

        if (!empty($keyword)) {
            $query->where(function ($q) use ($keyword) {
                $q->where('product_name', 'like', "%{$keyword}%")
                  ->orWhere('product_code', 'like', "%{$keyword}%");
            });
        }

        $products = $query->get()->map(function ($p) {
            $mainMaterial = $p->materials->first()?->material;

            return [
                'id' => $p->id,
                'productCode' => $p->product_code,
                'productName' => $p->product_name,
                'dimensions' => [
                    'length' => (float) $p->dimension_length,
                    'width' => (float) $p->dimension_width,
                    'height' => (float) $p->dimension_height,
                    'unit' => $p->unit ?? 'cm',
                ],
                'stock' => $p->stock,
                'mainMaterial' => $mainMaterial ? [
                    'id' => $mainMaterial->id,
                    'materialName' => $mainMaterial->material_name,
                    'grade' => $mainMaterial->grade,
                    'woodForm' => $mainMaterial->wood_form,
                ] : null,
            ];
        });

        return ResponseHelper::success($response, $products, 'Product catalogs retrieved successfully');
    }

    public function packings(Request $request, Response $response): Response
    {
        $packings = Packing::all()->map(fn ($item) => [
            'id' => $item->id,
            'packingName' => $item->packing_name,
            'description' => $item->description,
        ]);

        return ResponseHelper::success($response, $packings, 'Packings retrieved successfully');
    }

    public function finishings(Request $request, Response $response): Response
    {
        $finishings = FinishingType::all()->map(fn ($item) => [
            'id' => $item->id,
            'finishingName' => $item->name,
            'description' => $item->description,
        ]);

        return ResponseHelper::success($response, $finishings, 'Finishings retrieved successfully');
    }

    public function stuffings(Request $request, Response $response): Response
    {
        $stuffings = Stuffing::all()->map(fn ($item) => [
            'id' => $item->id,
            'stuffingName' => $item->stuffing_name,
            'description' => $item->description,
        ]);

        return ResponseHelper::success($response, $stuffings, 'Stuffings retrieved successfully');
    }
}
