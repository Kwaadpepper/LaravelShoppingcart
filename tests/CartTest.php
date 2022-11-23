<?php

namespace Gloudemans\Tests\Shoppingcart;

use Carbon\Carbon;
use Gloudemans\Shoppingcart\Cart;
use Gloudemans\Shoppingcart\CartItem;
use Gloudemans\Shoppingcart\CartItemOptions;
use Gloudemans\Shoppingcart\ShoppingcartServiceProvider;
use Gloudemans\Tests\Shoppingcart\Fixtures\BuyableProduct;
use Gloudemans\Tests\Shoppingcart\Fixtures\BuyableProductTrait;
use Gloudemans\Tests\Shoppingcart\Fixtures\Identifiable;
use Gloudemans\Tests\Shoppingcart\Fixtures\ProductModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Money\Currency;
use Money\Money;
use Orchestra\Testbench\TestCase;

class CartTest extends TestCase
{
    use CartAssertions;

    /**
     * Set the package service provider.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            ShoppingcartServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('cart.database.connection', 'testing');

        $app['config']->set('session.driver', 'array');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->afterResolving('migrator', function ($migrator) {
            $migrator->path(realpath(__DIR__.'/../src/Database/migrations'));
        });
    }

    /** @test */
    public function it_has_a_default_instance()
    {
        $cart = $this->getCart();

        $this->assertEquals(Cart::DEFAULT_INSTANCE, $cart->currentInstance());
    }

    /** @test */
    public function it_can_have_multiple_instances()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'id'   => 1,
            'name' => 'First item',
        ]));

        $cart->instance('wishlist')->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Second item',
        ]));

        $this->assertItemsInCart(1, $cart->instance(Cart::DEFAULT_INSTANCE));
        $this->assertItemsInCart(1, $cart->instance('wishlist'));
    }

    /** @test */
    public function it_can_add_an_item()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_will_return_the_cartitem_of_the_added_item()
    {
        Event::fake();

        $cart = $this->getCart();

        $cartItem = $cart->add(new BuyableProduct());

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals('027c91341fd5cf4d2579b49c4b6a90da', $cartItem->rowId);

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_multiple_buyable_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add([new BuyableProduct([
            'id' => 1,
        ]), new BuyableProduct([
            'id' => 2,
        ])]);

        $this->assertEquals(2, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_will_return_an_array_of_cartitems_when_you_add_multiple_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cartItems = $cart->add([new BuyableProduct([
            'id' => 1,
        ]), new BuyableProduct([
            'id' => 2,
        ])]);

        $this->assertTrue(is_array($cartItems));
        $this->assertCount(2, $cartItems);
        $this->assertIsIterable($cartItems);
        if (is_iterable($cartItems)) {
            $this->assertContainsOnlyInstancesOf(CartItem::class, $cartItems);
        }

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_an_item_from_attributes()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, new Money(1000, new Currency('USD')));

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_an_item_from_an_array()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(['id' => 1, 'name' => 'Test item', 'qty' => 1, 'price' => new Money(1000, new Currency('USD')), 'weight' => 550]);

        $this->assertEquals(1, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_multiple_array_items_at_once()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add([
            ['id' => 1, 'name' => 'Test item 1', 'qty' => 1, 'price' => new Money(1000, new Currency('USD')), 'weight' => 550],
            ['id' => 2, 'name' => 'Test item 2', 'qty' => 1, 'price' => new Money(1000, new Currency('USD')), 'weight' => 550],
        ]);

        $this->assertEquals(2, $cart->count());

        Event::assertDispatched('cart.added');
    }

    /** @test */
    public function it_can_add_an_item_with_options()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct(), 1, new CartItemOptions(['size' => 'XL', 'color' => 'red']));

        $cartItem = $cart->get('07d5da5550494c62daf9993cf954303f');

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals('XL', $cartItem->options->size);
        $this->assertEquals('red', $cartItem->options->color);

        Event::assertDispatched('cart.added');
    }

    /**
     * @test
     */
    public function it_will_validate_the_identifier()
    {
        $this->expectException(\TypeError::class);

        $cart = $this->getCart();

        $cart->add(null, 'Some title', 1, new Money(1000, new Currency('USD')));
    }

    /**
     * @test
     */
    public function it_will_validate_the_quantity()
    {
        $this->expectException(\TypeError::class);

        $cart = $this->getCart();

        $cart->add(1, 'Some title', 'invalid', new Money(1000, new Currency('USD')));
    }

    /**
     * @test
     */
    public function it_will_validate_the_price()
    {
        $this->expectException(\TypeError::class);

        $cart = $this->getCart();

        $cart->add(1, 'Some title', 1, 'invalid');
    }

    /**
     * @test
     */
    public function it_will_validate_the_weight()
    {
        $this->expectException(\TypeError::class);

        $cart = $this->getCart();

        $cart->add(1, 'Some title', 1, new Money(1000, new Currency('USD')), 'invalid');
    }

    /** @test */
    public function it_will_update_the_cart_if_the_item_already_exists_in_the_cart()
    {
        $cart = $this->getCart();

        $item = new BuyableProduct();

        $cart->add($item);
        $cart->add($item);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    /** @test */
    public function it_will_keep_updating_the_quantity_when_an_item_is_added_multiple_times()
    {
        $cart = $this->getCart();

        $item = new BuyableProduct();

        $cart->add($item);
        $cart->add($item);
        $cart->add($item);

        $this->assertItemsInCart(3, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    /** @test */
    public function it_can_update_the_quantity_of_an_existing_item_in_the_cart()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', 2);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);

        Event::assertDispatched('cart.updated');
    }

    /** @test */
    public function it_can_update_an_existing_item_in_the_cart_from_a_buyable()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProductTrait([
            'description' => 'Description',
        ]));

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', new BuyableProductTrait([
            'name'        => '',
            'description' => 'Different description',
        ]));

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('Different description', $cart->get('027c91341fd5cf4d2579b49c4b6a90da')->name);

        Event::assertDispatched('cart.updated');
    }

    /** @test */
    public function it_can_update_an_existing_item_in_the_cart_from_an_array()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProductTrait([
            'description' => 'Description',
        ]));

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', ['name' => 'Different description']);

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('Different description', $cart->get('027c91341fd5cf4d2579b49c4b6a90da')->name);

        Event::assertDispatched('cart.updated');
    }

    /**
     * @test
     */
    public function it_will_throw_an_exception_if_a_rowid_was_not_found()
    {
        $this->expectException(\Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException::class);

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->update('none-existing-rowid', new BuyableProduct([
            'description' => 'Different description',
        ]));
    }

    /** @test */
    public function it_will_regenerate_the_rowid_if_the_options_changed()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(), 1, new CartItemOptions(['color' => 'red']));

        $cart->update('ea65e0bdcd1967c4b3149e9e780177c0', ['options' => new CartItemOptions(['color' => 'blue'])]);

        $this->assertItemsInCart(1, $cart);
        $this->assertEquals('7e70a1e9aaadd18c72921a07aae5d011', $cart->content()->first()->rowId);
        $this->assertEquals('blue', $cart->get('7e70a1e9aaadd18c72921a07aae5d011')->options->color);
    }

    /** @test */
    public function it_will_add_the_item_to_an_existing_row_if_the_options_changed_to_an_existing_rowid()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(), 1, new CartItemOptions(['color' => 'red']));
        $cart->add(new BuyableProduct(), 1, new CartItemOptions(['color' => 'blue']));

        $cart->update('7e70a1e9aaadd18c72921a07aae5d011', ['options' => new CartItemOptions(['color' => 'red'])]);

        $this->assertItemsInCart(2, $cart);
        $this->assertRowsInCart(1, $cart);
    }

    /** @test */
    public function it_will_keep_items_sequence_if_the_options_changed()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(), 1, new CartItemOptions(['color' => 'red']));
        $cart->add(new BuyableProduct(), 1, new CartItemOptions(['color' => 'green']));
        $cart->add(new BuyableProduct(), 1, new CartItemOptions(['color' => 'blue']));

        $cart->update($cart->content()->values()[1]->rowId, ['options' => new CartItemOptions(['color' => 'yellow'])]);

        $this->assertRowsInCart(3, $cart);
        $this->assertEquals('yellow', $cart->content()->values()[1]->options->color);
    }

    /** @test */
    public function it_can_remove_an_item_from_the_cart()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->remove('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    /** @test */
    public function it_will_remove_the_item_if_its_quantity_was_set_to_zero()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', 0);

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    /** @test */
    public function it_will_remove_the_item_if_its_quantity_was_set_negative()
    {
        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', -1);

        $this->assertItemsInCart(0, $cart);
        $this->assertRowsInCart(0, $cart);

        Event::assertDispatched('cart.removed');
    }

    /** @test */
    public function it_can_get_an_item_from_the_cart_by_its_rowid()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertInstanceOf(CartItem::class, $cartItem);
    }

    /** @test */
    public function it_can_get_the_content_of_the_cart()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct());
        $cart->add(new BuyableProduct([
            'id' => 2,
        ]));

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertCount(2, $content);
    }

    /** @test */
    public function it_will_return_an_empty_collection_if_the_cart_is_empty()
    {
        $cart = $this->getCart();

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertCount(0, $content);
    }

    /** @test */
    public function it_will_include_the_tax_and_subtotal_when_converted_to_an_array()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct());
        $cart->add(new BuyableProduct([
            'id' => 2,
        ]));

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(new Money(1000, $cartItem->price->getCurrency()), $cartItem->price());
        $this->assertEquals(new Money(1000, $cartItem->price->getCurrency()), $cartItem->subtotal());
        $this->assertEquals(new Money(210, $cartItem->price->getCurrency()), $cartItem->tax());
        $this->assertEquals(new Money(1210, $cartItem->price->getCurrency()), $cartItem->total());

        $content = $cart->content();

        $this->assertInstanceOf(Collection::class, $content);
        $this->assertEquals([
            '027c91341fd5cf4d2579b49c4b6a90da' => [
                'rowId'    => '027c91341fd5cf4d2579b49c4b6a90da',
                'id'       => 1,
                'name'     => 'Item name',
                'qty'      => 1,
                'price'    => '10.00',
                'subtotal' => '10.00',
                'tax'      => '2.10',
                'total'    => '12.10',
                'options'  => [],
                'discount' => '0.00',
                'weight'   => 0,

            ],
            '370d08585360f5c568b18d1f2e4ca1df' => [
                'rowId'    => '370d08585360f5c568b18d1f2e4ca1df',
                'id'       => 2,
                'name'     => 'Item name',
                'qty'      => 1,
                'price'    => '10.00',
                'subtotal' => '10.00',
                'tax'      => '2.10',
                'total'    => '12.10',
                'options'  => [],
                'discount' => '0.00',
                'weight'   => 0,
            ],
        ], $content->toArray());
    }

    /** @test */
    public function it_can_destroy_a_cart()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $this->assertItemsInCart(1, $cart);

        $cart->destroy();

        $this->assertItemsInCart(0, $cart);
    }

    /** @test */
    public function it_can_get_the_total_price_of_the_cart_content()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'First item',
        ]));
        $cart->add(new BuyableProduct([
            'id'    => 2,
            'name'  => 'Second item',
            'price' => 2500,
        ]), 2);

        $this->assertItemsInCart(3, $cart);
        $this->assertEquals(new Money(6000, new Currency('USD')), $cart->subtotal());
    }

    /** @test */
    public function it_can_search_the_cart_for_a_specific_item()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Some item',
        ]));
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Another item',
        ]));

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->name == 'Some item';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
        $this->assertCount(1, $cartItem);
        $this->assertInstanceOf(CartItem::class, $cartItem->first());
        $this->assertEquals(1, $cartItem->first()->id);
    }

    /** @test */
    public function it_can_search_the_cart_for_multiple_items()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Some item',
        ]));
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Some item',
        ]));
        $cart->add(new BuyableProduct([
            'id'   => 3,
            'name' => 'Another item',
        ]));

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->name == 'Some item';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
    }

    /** @test */
    public function it_can_search_the_cart_for_a_specific_item_with_options()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Some item',
        ]), 1, new CartItemOptions(['color' => 'red']));
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Another item',
        ]), 1, new CartItemOptions(['color' => 'blue']));

        $cartItem = $cart->search(function ($cartItem, $rowId) {
            return $cartItem->options->color == 'red';
        });

        $this->assertInstanceOf(Collection::class, $cartItem);
        $this->assertCount(1, $cartItem);
        $this->assertInstanceOf(CartItem::class, $cartItem->first());
        $this->assertEquals(1, $cartItem->first()->id);
    }

    /** @test */
    public function it_will_associate_the_cart_item_with_a_model_when_you_add_a_buyable()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(BuyableProduct::class, $cartItem->associatedModel);
    }

    /** @test */
    public function it_can_associate_the_cart_item_with_a_model()
    {
        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, new Money(1000, new Currency('USD')));

        $cart->associate('027c91341fd5cf4d2579b49c4b6a90da', new ProductModel());

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(ProductModel::class, $cartItem->associatedModel);
    }

    /**
     * @test
     */
    public function it_will_throw_an_exception_when_a_non_existing_model_is_being_associated()
    {
        $this->expectException(\Gloudemans\Shoppingcart\Exceptions\UnknownModelException::class);
        $this->expectExceptionMessage('The supplied model SomeModel does not exist.');

        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, new Money(1000, new Currency('USD')));

        $cart->associate('027c91341fd5cf4d2579b49c4b6a90da', 'SomeModel');
    }

    /** @test */
    public function it_can_get_the_associated_model_of_a_cart_item()
    {
        $cart = $this->getCart();

        $cart->add(1, 'Test item', 1, new Money(1000, new Currency('USD')));

        $cart->associate('027c91341fd5cf4d2579b49c4b6a90da', new ProductModel());

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertInstanceOf(ProductModel::class, $cartItem->model());
        $this->assertEquals('Some value', $cartItem->model()->someValue);
    }

    /** @test */
    public function it_can_calculate_the_subtotal_of_a_cart_item()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name'  => 'Some title',
            'price' => 999,
        ]), 3);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(new Money(2997, new Currency('USD')), $cartItem->subtotal());
    }

    /** @test */
    public function it_can_calculate_tax_based_on_the_default_tax_rate_in_the_config()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Some title',
        ]), 1);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(new Money(210, new Currency('USD')), $cartItem->tax());
    }

    /** @test */
    public function it_can_calculate_tax_based_on_the_specified_tax()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Some title',
        ]), 1);

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 0.19);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(new Money(190, new Currency('USD')), $cartItem->tax());
    }

    /** @test */
    public function it_can_calculate_the_total_tax_for_all_cart_items()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Some title',
        ]), 1);
        $cart->add(new BuyableProduct([
            'id'    => 2,
            'name'  => 'Some title',
            'price' => 2000,
        ]), 2);

        $this->assertEquals(new Money(1050, new Currency('USD')), $cart->tax());
    }

    /** @test */
    public function it_can_access_tax_as_percentage()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Some title',
        ]), 1);

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 0.19);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(0.19, $cartItem->taxRate);
    }

    /** @test */
    public function it_can_return_the_subtotal()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct(), 1);
        $cart->add(new BuyableProduct([
            'id'    => 2,
            'price' => 2000,
        ]), 2);

        $this->assertEquals(new Money(5000, new Currency('USD')), $cart->subtotal());
    }

    /** @test */
    public function it_can_store_the_cart_in_a_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->store($identifier = 123);

        Event::assertDispatched('cart.stored');

        $serialized = serialize($cart->content());

        $this->assertDatabaseHas('shoppingcart', ['identifier' => $identifier, 'instance' => 'default', 'content' => $serialized]);
    }

    /** @test */
    public function it_can_store_and_retrieve_cart_from_the_database_with_correct_timestamps()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        /* Sleep as database does not store ms */
        $beforeStore = Carbon::now();
        sleep(1);

        $cart->store($identifier = 123);

        sleep(1);
        $afterStore = Carbon::now();

        $cart->restore($identifier);

        $this->assertTrue($beforeStore->lessThanOrEqualTo($cart->createdAt()) && $afterStore->greaterThanOrEqualTo($cart->createdAt()));
        $this->assertTrue($beforeStore->lessThanOrEqualTo($cart->updatedAt()) && $afterStore->greaterThanOrEqualTo($cart->updatedAt()));

        /* Sleep as database does not store ms */
        $beforeSecondStore = Carbon::now();
        sleep(1);

        $cart->store($identifier);

        Event::assertDispatched('cart.stored');

        sleep(1);
        $afterSecondStore = Carbon::now();

        $cart->restore($identifier);

        $this->assertTrue($beforeStore->lessThanOrEqualTo($cart->createdAt()) && $afterStore->greaterThanOrEqualTo($cart->createdAt()));
        $this->assertTrue($beforeSecondStore->lessThanOrEqualTo($cart->updatedAt()) && $afterSecondStore->greaterThanOrEqualTo($cart->updatedAt()));
    }

    /**
     * @test
     */
    public function it_will_throw_an_exception_when_a_cart_was_already_stored_using_the_specified_identifier()
    {
        $this->expectException(\Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException::class);
        $this->expectExceptionMessage('A cart with identifier 123 was already stored.');

        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->store($identifier = 123);

        Event::assertDispatched('cart.stored');

        $cart->store($identifier);
    }

    /** @test */
    public function it_can_restore_a_cart_from_the_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->store($identifier = 123);

        $cart->destroy();

        $this->assertItemsInCart(0, $cart);

        $cart->restore($identifier);

        Event::assertDispatched('cart.restored');

        $this->assertItemsInCart(1, $cart);

        $this->assertDatabaseMissing('shoppingcart', ['identifier' => $identifier, 'instance' => 'default']);
    }

    /** @test */
    public function it_will_just_keep_the_current_instance_if_no_cart_with_the_given_identifier_was_stored()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        $cart = $this->getCart();

        $cart->restore($identifier = 123);

        $this->assertItemsInCart(0, $cart);
    }

    /** @test */
    public function it_can_calculate_all_values()
    {
        $cart = $this->getCartDiscount(0.5);

        $cart->add(new BuyableProduct([
            'name' => 'First item',
        ]), 2);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 0.19);

        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->price);
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->discount());
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->subtotal());
        $this->assertEquals(new Money(190, new Currency('USD')), $cartItem->tax());
        $this->assertEquals(new Money(1190, new Currency('USD')), $cartItem->total());
    }

    /** @test */
    public function it_can_calculate_all_values_after_updating_from_array()
    {
        $cart = $this->getCartDiscount(0.5);
        $cart->add(new BuyableProduct([
            'name' => 'First item',
        ]), 1);

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', ['qty' => 2]);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 0.19);

        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->price);
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->discount());
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->subtotal());
        $this->assertEquals(new Money(190, new Currency('USD')), $cartItem->tax());
        $this->assertEquals(new Money(1190, new Currency('USD')), $cartItem->total());
    }

    /** @test */
    public function it_can_calculate_all_values_after_updating_from_buyable()
    {
        $cart = $this->getCartDiscount(0.5);
        $cart->add(new BuyableProduct([
            'name'  => 'First item',
            'price' => '5.00',
        ]), 2);

        $cart->update('027c91341fd5cf4d2579b49c4b6a90da', new BuyableProduct([
            'name' => 'First item',
        ]));

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 0.19);

        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->price);
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->discount());
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->subtotal());
        $this->assertEquals(new Money(190, new Currency('USD')), $cartItem->tax());
        $this->assertEquals(new Money(1190, new Currency('USD')), $cartItem->total());
    }

    /** @test */
    public function can_change_tax_globally()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Item',
        ]), 2);

        $cart->setGlobalTax(0);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(new Money(2000, new Currency('USD')), $cartItem->total());
    }

    /** @test */
    public function can_change_discount_globally()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name' => 'Item',
        ]), 2);

        $cart->setGlobalTax(0);
        $cart->setGlobalDiscount(0.5);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->total());
    }

    /** @test */
    public function it_can_merge_multiple_carts()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCartDiscount(0.5);
        $cart->add(new BuyableProduct([
            'name' => 'Item',
        ]), 1);
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Item 2',
        ]), 1);
        $cart->store('test');

        $cart2 = $this->getCart();
        $cart2->instance('test2');
        $cart2->setGlobalTax(0);
        $cart2->setGlobalDiscount(0);

        $this->assertEquals(0, $cart2->countItems());

        $cart2->merge('test');

        $this->assertEquals('2', $cart2->countItems());
        $this->assertEquals(new Money(2000, new Currency('USD')), $cart2->total());

        $cart3 = $this->getCart();
        $cart3->instance('test3');
        $cart3->setGlobalTax(0);
        $cart3->setGlobalDiscount(0);

        $cart3->merge('test', true);

        $this->assertEquals(new Money(1000, new Currency('USD')), $cart3->total());
    }

    /** @test */
    public function it_cant_merge_non_existing_cart()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);
        Event::fake();
        $cart = $this->getCartDiscount(0.5);
        $cart->add(new BuyableProduct([
            'name' => 'Item',
        ]), 1);
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Item 2',
        ]), 1);
        $this->assertEquals(false, $cart->merge('doesNotExist'));
        $this->assertEquals(2, $cart->countItems());
    }

    /** @test */
    public function cart_can_calculate_all_values()
    {
        $cart = $this->getCartDiscount(0.5);
        $cart->add(new BuyableProduct([
            'name' => 'First item',
        ]), 1);
        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 0.19);
        $this->assertEquals(new Money(1000, new Currency('USD')), $cart->price());
        $this->assertEquals(new Money(500, new Currency('USD')), $cart->discount());
        $this->assertEquals(new Money(500, new Currency('USD')), $cart->subtotal());
        $this->assertEquals(new Money(95, new Currency('USD')), $cart->tax());
        $this->assertEquals(new Money(595, new Currency('USD')), $cart->total());
    }

    /** @test */
    public function can_set_cart_item_discount()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct([
            'name' => 'First item',
        ]), 1);
        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $cart->setDiscount('027c91341fd5cf4d2579b49c4b6a90da', 0.5);

        $this->assertEquals(0.5, $cartItem->discount);
    }

    /** @test */
    public function can_set_cart_item_discount_using_money()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct([
            'name' => 'First item',
        ]), 1);
        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $cart->setDiscount('027c91341fd5cf4d2579b49c4b6a90da', Money::USD(230));

        $this->assertTrue(Money::USD(230)->equals($cartItem->discount));
    }

    /** @test */
    public function can_set_cart_item_weight_and_calculate_total_weight()
    {
        $cart = $this->getCart();
        $cart->add(new BuyableProduct([
            'name'   => 'First item',
            'weight' => 250,
        ]), 2);
        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');
        $this->assertEquals(500, $cart->weight());
        $this->assertEquals(500, $cartItem->weight());
    }

    /** @test */
    public function cart_can_create_and_restore_from_instance_identifier()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $identifier = new Identifiable('User1', 0);
        $cart = $this->getCart();

        $cart->instance($identifier);
        $this->assertEquals('User1', $cart->currentInstance());

        $cart->add(new BuyableProduct([
            'name'   => 'First item',
            'weight' => 250,
        ]), 2);
        $this->assertItemsInCart(2, $cart);

        $cart->store($identifier);
        $cart->destroy();
        $this->assertItemsInCart(0, $cart);

        $cart->restore($identifier);
        $this->assertItemsInCart(2, $cart);
    }

    /** @test */
    public function cart_can_create_items_from_models_using_the_canbebought_trait()
    {
        $cart = $this->getCartDiscount(0.5);

        $cart->add(new BuyableProductTrait([
            'name' => 'First item',
        ]), 2);

        $cartItem = $cart->get('027c91341fd5cf4d2579b49c4b6a90da');

        $cart->setTax('027c91341fd5cf4d2579b49c4b6a90da', 0.19);

        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->price);
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->discount());
        $this->assertEquals(new Money(1000, new Currency('USD')), $cartItem->subtotal());
        $this->assertEquals(new Money(190, new Currency('USD')), $cartItem->tax());
        $this->assertEquals(new Money(1190, new Currency('USD')), $cartItem->total());
    }

    /** @test */
    public function it_does_allow_adding_cart_items_with_weight_and_options()
    {
        // https://github.com/bumbummen99/LaravelShoppingcart/pull/5
        $cart = $this->getCart();

        $cartItem = $cart->add('293ad', 'Product 1', 2, new Money(1000, new Currency('USD')), 550, new CartItemOptions(['size' => 'large']));

        $this->assertEquals(550, $cartItem->weight);
        $this->assertEquals(1100, $cartItem->weight());
        $this->assertTrue($cartItem->options->has('size'));
        $this->assertEquals('large', $cartItem->options->size);
    }

    /** @test */
    public function it_can_merge_without_dispatching_add_events()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        $cart = $this->getCartDiscount(0.5);
        $cart->add(new BuyableProduct([
            'name' => 'Item',
        ]), 1);
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Item 2',
        ]), 1);
        $cart->store('test');

        Event::fakeFor(function () {
            $cart2 = $this->getCart();
            $cart2->instance('test2');
            $cart2->setGlobalTax(0);
            $cart2->setGlobalDiscount(0);

            $this->assertEquals('0', $cart2->countItems());

            $cart2->merge('test', false, false, false);

            Event::assertNotDispatched('cart.added');
            Event::assertDispatched('cart.merged');

            $this->assertEquals(2, $cart2->countItems());
            $this->assertEquals(new Money(2000, new Currency('USD')), $cart2->total());
        });
    }

    /** @test */
    public function it_can_merge_dispatching_add_events()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        $cart = $this->getCartDiscount(0.5);
        $cart->add(new BuyableProduct([
            'name' => 'Item',
        ]), 1);
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Item 2',
        ]), 1);
        $cart->store('test');

        Event::fakeFor(function () {
            $cart2 = $this->getCart();
            $cart2->instance('test2');
            $cart2->setGlobalTax(0);
            $cart2->setGlobalDiscount(0);

            $this->assertEquals(0, $cart2->countItems());

            $cart2->merge('test');

            Event::assertDispatched('cart.added', 2);
            Event::assertDispatched('cart.merged');
            $this->assertEquals(2, $cart2->countItems());
            $this->assertEquals(new Money(2000, new Currency('USD')), $cart2->total());
        });
    }

    /** @test */
    public function it_can_store__mutiple_instances_of_the_cart_in_a_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();

        $cart->add(new BuyableProduct());

        $cart->store($identifier = 123);

        Event::assertDispatched('cart.stored');

        $serialized = serialize($cart->content());

        $newInstance = $this->getCart();
        $newInstance->instance($instanceName = 'someinstance');
        $newInstance->add(new BuyableProduct());
        $newInstance->store($identifier);

        Event::assertDispatched('cart.stored');

        $newInstanceSerialized = serialize($newInstance->content());

        $this->assertDatabaseHas('shoppingcart', ['identifier' => $identifier, 'instance' => Cart::DEFAULT_INSTANCE, 'content' => $serialized]);

        $this->assertDatabaseHas('shoppingcart', ['identifier' => $identifier, 'instance' => $instanceName, 'content' => $newInstanceSerialized]);
    }

    /** @test */
    public function it_can_calculate_the_total_price_of_the_items_in_cart()
    {
        $cart = $this->getCart();

        $cart->add(new BuyableProduct([
            'name'  => 'first item',
            'price' => 1000,
        ]), 5);
        $this->assertEquals(new Money(5000, new Currency('USD')), $cart->price());
    }

    /** @test */
    public function it_can_erase_saved_cart_from_the_database()
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ]);

        Event::fake();

        $cart = $this->getCart();
        $cart->add(new BuyableProduct([
            'name' => 'Item',
        ]), 1);
        $cart->add(new BuyableProduct([
            'id'   => 2,
            'name' => 'Item 2',
        ]), 1);
        $cart->store($identifier = 'test');
        $cart->erase($identifier);
        Event::assertDispatched('cart.erased');
        $this->assertDatabaseMissing('shoppingcart', ['identifier' => $identifier, 'instance' => Cart::DEFAULT_INSTANCE]);
    }

    /**
     * Get an instance of the cart.
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    private function getCart()
    {
        $session = $this->app->make('session');
        $events = $this->app->make('events');

        return new Cart($session, $events);
    }

    /**
     * Get an instance of the cart with discount.
     *
     * @param int $discount
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    private function getCartDiscount(float $discount = 0.5)
    {
        $cart = $this->getCart();
        $cart->setGlobalDiscount($discount);

        return $cart;
    }

    /**
     * Set the config number format.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeperator
     */
    private function setConfigFormat($decimals, $decimalPoint, $thousandSeperator)
    {
        $this->app['config']->set('cart.format.decimals', $decimals);
        $this->app['config']->set('cart.format.decimal_point', $decimalPoint);
        $this->app['config']->set('cart.format.thousand_separator', $thousandSeperator);
    }
}
