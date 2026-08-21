<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    /**
     * Valid product statuses.
     */
    const STATUS_DRAFT     = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_INACTIVE  = 'inactive';

    const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_INACTIVE,
    ];

    protected $fillable = [
        'product_category_id',
        'name',
        'slug',
        'sku',
        'short_description',
        'description',
        'price',
        'sale_price',
        'stock_quantity',
        'low_stock_threshold',
        'weight',
        'unit',
        'main_image',
        'is_featured',
        'is_active',
        'status',
        'meta_title',
        'meta_description',
    ];

    protected $casts = [
        'product_category_id' => 'integer',
        'price'               => 'decimal:2',
        'sale_price'          => 'decimal:2',
        'stock_quantity'      => 'integer',
        'low_stock_threshold' => 'integer',
        'weight'              => 'decimal:2',
        'is_featured'         => 'boolean',
        'is_active'           => 'boolean',
    ];

    /**
     * Get the category this product belongs to.
     */
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * Get all images for this product.
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * Get the primary image for this product.
     */
    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * Get inventory logs for this product.
     */
    public function inventoryLogs()
    {
        return $this->hasMany(ProductInventoryLog::class)->orderByDesc('created_at');
    }

    /**
     * Calculate the effective price (sale_price if set, otherwise price).
     */
    public function getEffectivePriceAttribute(): string
    {
        return $this->sale_price !== null ? $this->sale_price : $this->price;
    }

    /**
     * Check if product is in stock.
     */
    public function getInStockAttribute(): bool
    {
        return $this->stock_quantity > 0;
    }

    /**
     * Check if product has low stock.
     */
    public function getIsLowStockAttribute(): bool
    {
        return $this->stock_quantity <= $this->low_stock_threshold;
    }

    /**
     * Get public URL for main image.
     */
    public function getMainImageUrlAttribute(): ?string
    {
        if (!$this->main_image) {
            return null;
        }

        // If it's already a full URL, return as-is
        if (str_starts_with($this->main_image, 'http')) {
            return $this->main_image;
        }

        return asset('storage/' . $this->main_image);
    }

    /**
     * Get all reviews for this product.
     */
    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Get visible reviews for this product.
     */
    public function visibleReviews()
    {
        return $this->hasMany(ProductReview::class)->where('is_visible', true);
    }

    /**
     * Get the average rating of visible reviews.
     */
    public function getAverageRatingAttribute(): ?float
    {
        $avg = $this->visibleReviews()->avg('rating');
        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Get the total number of visible reviews.
     */
    public function getTotalReviewsAttribute(): int
    {
        return $this->visibleReviews()->count();
    }
}
