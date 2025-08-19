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
            'name' => 'required|string|max:255|unique:brands,name',
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'logo_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'website' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        if ($request->hasFile('logo_file')) {
            $file = $request->file('logo_file');
            $path = $file->store('brands', 'public'); // stores in storage/app/public/brands
            $data['logo'] = $path; // save path to DB
        }

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
            'name' => 'sometimes|string|max:255|unique:brands,name,' . $company->id,
            'description' => 'nullable|string',
            'logo' => 'nullable|string',
            'logo_file' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'website' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // Handle logo upload if a new file is provided
        if ($request->hasFile('logo_file')) {
            $file = $request->file('logo_file');
            $path = $file->store('brands', 'public'); // stores in storage/app/public/brands
            $data['logo'] = $path;
        }

        // Update slug if name changes
        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
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
