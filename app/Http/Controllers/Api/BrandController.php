<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Models\Brand;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class BrandController
{
    // Display all brand products or companies
    public function getAllBrand()
    {
        return response()->json([
            'success' => true,
            'data' => Brand::all()
        ]);
    }

    // Create a new brand.
    public function createBrand(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:companies,name',
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'website' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['slug'] = Str::slug($data['name']);

        $brand = Brand::create($data);

        return response()->json([
            'success' => true,
            'data' => $brand
        ], 201);
    }

    // Display the specified brand.
    public function getBrand($slug)
    {
        $brand = Brand::where('slug', $slug)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $brand,
        ]);
    }

    // Update the specified brand in storage.
    public function updateBrand(Request $request, $slug)
    {
        $company = Brand::where('slug', $slug)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:companies,name,' . $company->id,
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'website' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if (isset($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']);
        }

        $company->update($data);

        return response()->json([
            'success' => true,
            'data' => $company
        ]);
    }

    // Remove the specified brand from storage.
    public function deleteBrand($slug)
    {
        $company = Brand::where('slug', $slug)->firstOrFail();
        $company->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Brand deleted successfully'
        ]);
    }
}
