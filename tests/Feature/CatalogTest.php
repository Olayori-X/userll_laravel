<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Category;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeSeller(string $store = 'Ada Phones'): User
    {
        $user = User::factory()->create(); // factory users are email-verified
        SellerProfile::create([
            'user_id' => $user->id,
            'store_name' => $store,
            'slug' => SellerProfile::uniqueSlug($store),
            'state' => 'Lagos',
            'city' => 'Ikeja',
        ]);

        return $user->fresh();
    }

    private function listingPayload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'title' => 'iPhone 13 128GB',
            'description' => 'Clean iPhone 13, battery health 90 percent, comes with box.',
            'price' => '450,000',
            'condition' => 'used',
            'stock' => 2,
            'city' => 'Ikeja',
            'state' => 'Lagos',
        ], $overrides);
    }

    public function test_user_can_become_seller_only_once(): void
    {
        $user = User::factory()->create();

        $payload = ['store_name' => 'Bola Shoes', 'state' => 'Lagos', 'city' => 'Yaba'];

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/seller/profile', $payload)
            ->assertCreated()->assertJsonPath('data.slug', 'bola-shoes');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/seller/profile', $payload)
            ->assertStatus(409);
    }

    public function test_unverified_user_cannot_become_seller(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/seller/profile', ['store_name' => 'Bola Shoes', 'state' => 'Lagos', 'city' => 'Yaba'])
            ->assertForbidden(); // the "verified" middleware blocks unverified emails
    }

    public function test_non_seller_cannot_create_listing(): void
    {
        $category = Category::factory()->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->listingPayload($category))
            ->assertForbidden();
    }

    public function test_seller_creates_draft_with_price_in_kobo_and_images(): void
    {
        $seller = $this->makeSeller();
        $category = Category::factory()->create();

        $response = $this->actingAs($seller, 'sanctum')->post('/api/v1/seller/listings', $this->listingPayload($category) + [
            'images' => [UploadedFile::fake()->image('a.jpg', 800, 800), UploadedFile::fake()->image('b.png', 800, 800)],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.price', 45000000)
            ->assertJsonPath('data.price_formatted', '₦450,000.00')
            ->assertJsonCount(2, 'data.images');

        $listing = Listing::firstOrFail();
        $this->assertSame($seller->id, $listing->seller_id);
        $this->assertCount(2, Storage::disk('public')->allFiles("listings/{$listing->id}"));
    }

    public function test_client_cannot_set_seller_or_status(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller('Other Store');
        $category = Category::factory()->create();

        $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->listingPayload($category, ['seller_id' => $other->id, 'status' => 'active']))
            ->assertCreated();

        $listing = Listing::firstOrFail();
        $this->assertSame($seller->id, $listing->seller_id);
        $this->assertSame(ListingStatus::Draft, $listing->status);
    }

    public function test_price_validation(): void
    {
        $seller = $this->makeSeller();
        $category = Category::factory()->create();

        foreach (['abc', '12.345', '5', '999,999,999,999'] as $bad) {
            $this->actingAs($seller, 'sanctum')
                ->postJson('/api/v1/seller/listings', $this->listingPayload($category, ['price' => $bad]))
                ->assertUnprocessable()->assertJsonValidationErrors('price');
        }
    }

    public function test_cannot_publish_without_photo_then_can_after_adding_one(): void
    {
        $seller = $this->makeSeller();
        $category = Category::factory()->create();
        $id = $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->listingPayload($category))
            ->json('data.id');

        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/listings/{$id}/publish")
            ->assertUnprocessable()->assertJsonValidationErrors('images');

        $this->actingAs($seller, 'sanctum')->post("/api/v1/seller/listings/{$id}/images", [
            'images' => [UploadedFile::fake()->image('a.jpg', 800, 800)],
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($seller, 'sanctum')->postJson("/api/v1/seller/listings/{$id}/publish")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_small_or_non_image_uploads_are_rejected(): void
    {
        $seller = $this->makeSeller();
        $category = Category::factory()->create();
        $id = $this->actingAs($seller, 'sanctum')
            ->postJson('/api/v1/seller/listings', $this->listingPayload($category))
            ->json('data.id');

        $this->actingAs($seller, 'sanctum')->post("/api/v1/seller/listings/{$id}/images", [
            'images' => [UploadedFile::fake()->image('tiny.jpg', 100, 100)],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->actingAs($seller, 'sanctum')->post("/api/v1/seller/listings/{$id}/images", [
            'images' => [UploadedFile::fake()->create('script.php', 10, 'application/x-php')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_seller_cannot_touch_another_sellers_listing(): void
    {
        $owner = $this->makeSeller();
        $intruder = $this->makeSeller('Intruder Store');
        $listing = Listing::factory()->create(['seller_id' => $owner->id]);

        $this->actingAs($intruder, 'sanctum')->patchJson("/api/v1/seller/listings/{$listing->id}", ['title' => 'Hijacked title'])
            ->assertNotFound();
        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/v1/seller/listings/{$listing->id}")->assertNotFound();
        $this->actingAs($intruder, 'sanctum')->postJson("/api/v1/seller/listings/{$listing->id}/publish")->assertNotFound();
    }

    public function test_setting_stock_to_zero_marks_sold_out_and_refill_reactivates(): void
    {
        $seller = $this->makeSeller();
        $listing = Listing::factory()->create(['seller_id' => $seller->id]);

        $this->actingAs($seller, 'sanctum')->patchJson("/api/v1/seller/listings/{$listing->id}", ['stock' => 0])
            ->assertOk()->assertJsonPath('data.status', 'sold_out');

        $this->actingAs($seller, 'sanctum')->patchJson("/api/v1/seller/listings/{$listing->id}", ['stock' => 3])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_public_list_shows_only_active_in_stock_listings(): void
    {
        $seller = $this->makeSeller();
        Listing::factory()->create(['seller_id' => $seller->id, 'title' => 'Visible one']);
        Listing::factory()->draft()->create(['seller_id' => $seller->id, 'title' => 'Draft one']);
        Listing::factory()->soldOut()->create(['seller_id' => $seller->id, 'title' => 'Sold out one']);

        $this->getJson('/api/v1/listings')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Visible one');
    }

    public function test_search_filters_and_sorting(): void
    {
        $seller = $this->makeSeller();
        $phones = Category::factory()->create(['slug' => 'phones']);
        $shoes = Category::factory()->create(['slug' => 'shoes']);

        Listing::factory()->create(['seller_id' => $seller->id, 'category_id' => $phones->id, 'title' => 'Samsung Galaxy S21', 'price' => 30_000_000]);
        Listing::factory()->create(['seller_id' => $seller->id, 'category_id' => $phones->id, 'title' => 'iPhone 13', 'price' => 45_000_000, 'state' => 'Abuja', 'city' => 'Wuse']);
        Listing::factory()->create(['seller_id' => $seller->id, 'category_id' => $shoes->id, 'title' => 'Nike Air Max', 'price' => 6_000_000]);

        $this->getJson('/api/v1/listings?q=galaxy')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings?category=phones')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/listings?state=Abuja')->assertJsonCount(1, 'data');
        // Prices above: S21 is ₦300,000, iPhone ₦450,000, Nike ₦60,000
        $this->getJson('/api/v1/listings?min_price=100k')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/listings?max_price=100k')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/listings?sort=price_asc')->assertJsonPath('data.0.title', 'Nike Air Max');
        $this->getJson('/api/v1/listings?sort=price_desc')->assertJsonPath('data.0.title', 'iPhone 13');
        $this->getJson('/api/v1/listings?min_price=abc')->assertUnprocessable();
        $this->getJson('/api/v1/listings?per_page=500')->assertUnprocessable();
    }

    public function test_search_treats_percent_sign_literally(): void
    {
        $seller = $this->makeSeller();
        Listing::factory()->create(['seller_id' => $seller->id, 'title' => 'Plain item']);

        $this->getJson('/api/v1/listings?q=%25')->assertJsonCount(0, 'data');
    }

    public function test_public_detail_hides_drafts_and_shows_seller_without_private_data(): void
    {
        $seller = $this->makeSeller();
        $live = Listing::factory()->create(['seller_id' => $seller->id]);
        $draft = Listing::factory()->draft()->create(['seller_id' => $seller->id]);

        $this->getJson("/api/v1/listings/{$draft->slug}")->assertNotFound();

        $response = $this->getJson("/api/v1/listings/{$live->slug}")->assertOk();
        $response->assertJsonPath('data.seller.store_name', 'Ada Phones');
        $this->assertStringNotContainsString($seller->email, $response->getContent());
    }

    public function test_seller_listings_filter_and_store_page(): void
    {
        $a = $this->makeSeller('Store A');
        $b = $this->makeSeller('Store B');
        Listing::factory()->create(['seller_id' => $a->id]);
        Listing::factory()->count(2)->create(['seller_id' => $b->id]);

        $this->getJson('/api/v1/listings?seller=store-b')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/sellers/store-a')->assertOk()->assertJsonPath('data.store_name', 'Store A');
    }

    public function test_categories_endpoint_returns_tree(): void
    {
        $parent = Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        Category::factory()->create(['name' => 'Laptops', 'slug' => 'laptops', 'parent_id' => $parent->id]);
        Category::factory()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_active' => false]);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.children.0.slug', 'laptops');
    }
}
