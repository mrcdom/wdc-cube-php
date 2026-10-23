# Templates and patterns

Taken from the Shopping application (`src/Shopping/Presentation`). Adapt the names; keep
the shape.

## Contents

1. Wiring: place, route, keys
2. A screen module, complete
3. The React view and its registration
4. GUARD presenter
5. SLOT OWNER presenter
6. CHILD segment presenter
7. Taking the user to another screen
8. Changing your own state (no navigation)
9. Timers and listeners
10. Remembering a reader preference
11. Testing without a browser

---

## 1. Wiring: place, route, keys

```php
// Place.php - a case per place; the id grows with depth
enum Place: int implements CubePlace
{
    case ROOT = 0;
    case OPEN = 1;
    case RESTRICTED = 2;
    case LOGIN = 3;
    case HOME = 4;
    case CART = 5;
    case PRODUCT = 6;
    case ORDERS = 8;                                  // new: level 3, inside HOME

    public function name(): string
    {
        return match ($this) {
            // ...
            self::ORDERS => 'orders',                 // the name in the address
        };
    }

    public function createPresenter(CubeApplication $app): CubePresenter
    {
        // ...
        return match ($this) {
            // ...
            self::ORDERS => new OrdersPresenter($app),
        };
    }

    public function go(ShoppingApplication $app, CubeIntent $intent): bool
    {
        return match ($this) {
            // ...
            self::ORDERS => Routes::orders($app, $intent),
        };
    }
}
```

```php
// Routes.php - the chain of places IS the face
public static function orders(ShoppingApplication $app, ?CubeIntent $intent = null): bool
{
    return $app->navigate()->step(Place::ROOT)->step(Place::RESTRICTED)->step(Place::HOME)->step(Place::ORDERS)
        ->execute($intent ?? $app->newIntent());
}
```

```php
// Presenter/Restricted/Orders/OrdersKeys.php - the ONLY file that knows these names
/** Keys da lista de pedidos: `orders?or-status=paid&or-page=2`. */
final class OrdersKeys extends HomeKeys            // inherits userId and ownerSlot from the root
{
    /** Prefixo dos nomes deste arquivo - único na aplicação. Escolha um que ainda não exista. */
    private const string PREFIX = 'or';

    private const string P_STATUS = self::PREFIX . '-status';   // parameter: goes to the address
    private const string P_PAGE = self::PREFIX . '-page';
    private const string A_ROW = self::PREFIX . '-row';         // attribute: never in the address

    private const array STATUSES = ['open', 'paid', 'shipped'];

    /** Validated on READ: the address is outside input. */
    public ?string $status {
        get {
            $raw = $this->intent->getParameterAsString(self::P_STATUS);
            return in_array($raw, self::STATUSES, true) ? $raw : null;
        }
        set (?string $value) {
            $this->intent->setParameter(self::P_STATUS, $value);
        }
    }

    public int $page {
        get => min(max($this->intent->getParameterAsInt(self::P_PAGE) ?? 1, 1), 10_000);
        set (int $value) {
            $this->intent->setParameter(self::P_PAGE, $value > 1 ? $value : null);   // the default stays out
        }
    }

    /** The row already in hand when opening the detail from the list. */
    public ?OrderInfo $row {
        get {
            $row = $this->intent->getAttribute(self::A_ROW);
            return $row instanceof OrderInfo ? $row : null;
        }
        set (?OrderInfo $value) {
            $this->intent->setAttribute(self::A_ROW, $value);
        }
    }

    public function place(): CubePlace
    {
        return Place::ORDERS;
    }
}
```

Every place has its keys - guards and screens without names too - and the classes mirror the
tree: `RootKeys` → `OpenKeys` → `LoginKeys`; `RootKeys` → `RestrictedKeys` → `HomeKeys` →
`CartKeys`/`ProductKeys`/`ReceiptKeys`. A keys without names only declares `place()`:

```php
/** Keys do carrinho: `cart`. */
final class CartKeys extends HomeKeys
{
    public function place(): CubePlace
    {
        return Place::CART;
    }
}
```

Navigation always goes through the destination's keys: `CartKeys::of($this->app)->go()` from
an event; `(new LoginKeys($this->app, $intent))->go()` when redirecting inside
`applyParameters`. A name is declared once,
in the OWNER's keys; a deeper place extends those keys to READ it. `KeysNamesTest` checks the
prefixes and the tables. Writing a property needs a variable (`$keys = new OrdersKeys(...);
$keys->page = 2;`): PHP refuses to write on `(new OrdersKeys(...))->page`.

Generate a new view `classId` with `php -r 'echo bin2hex(random_bytes(6)), PHP_EOL;'`.

## 2. A screen module, complete

```
Presentation/Presenter/Restricted/Products/
  ProductKeys.php          the typed reading of the intent: the names and their validation
  ProductPresenter.php     the control state, the cube contract, the event handlers
  ProductViewState.php     the rendering state (the contract with the React view)
  ProductInfo.php          the product as the screen shows it (public fields) + its projection
  ProductService.php       queries and commands the screen needs (repository + projection)
```

```php
// ProductViewState.php - public properties; null and blank are omitted on the wire
final class ProductViewState implements ViewState
{
    public ?ProductInfo $product = null;
    public int $errorCode = 0;            // non-nullable int: always sent, 0 = no error
    public ?string $errorMessage = null;
}
```

```php
// ProductInfo.php - a presentation struct: public fields, ready to show
final class ProductInfo
{
    public int $id = -1;
    public string $image = '';
    public string $name = 'unknown';
    public string $description = 'unknown';
    public float $price = 0.0;

    /** Only what the screen shows - the query selects exactly this. */
    public static function projection(): Product
    {
        return new Product()->withId(PV::INT)->withName(PV::STR)->withPrice(PV::FLOAT)->withDescription(PV::STR);
    }

    public static function create(?Product $product): ?self
    {
        if ($product === null) {
            return null;
        }
        $info = new self();
        $info->id = $product->id() ?? -1;
        $info->name = $product->name() ?? 'unknown';
        $info->price = $product->price() ?? 0.0;
        $info->description = $product->description() ?? 'unknown';
        $info->image = "image/product/{$info->id}.png";

        return $info;
    }
}
```

```php
// ProductService.php - the screen talks to the repository through here
final class ProductService
{
    private readonly ProductRepository $products;

    public function __construct()
    {
        $this->products = Beans::of(ProductRepository::class);   // looked up by interface
    }

    public function loadProductById(?int $productId): ProductInfo
    {
        if ($productId === null) {
            throw new WrongParametersException();
        }

        return ProductInfo::create($this->products->fetchById($productId, ProductInfo::projection()))
            ?? throw new ProductNotFoundException();   // the navigation rolls back; the caller shows the error
    }
}
```

```php
// ProductPresenter.php
/** @extends AbstractCubePresenter<ShoppingApplication> */
final class ProductPresenter extends AbstractCubePresenter
{
    private const int EVENT_OPEN_PRODUCTS = 1;
    private const int EVENT_ADD_TO_CART = 2;

    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly ProductViewState $state;
    private readonly ProductService $productService;
    private ?CubeViewSlot $ownerSlot = null;

    public function __construct(ShoppingApplication $app)
    {
        parent::__construct($app);
        $this->state = new ProductViewState();
        $this->productService = new ProductService();
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        $keys = new ProductKeys($this->app, $intent);
        $oldId = $this->state->product?->id;
        $newId = $keys->productId ?? throw new ProductNotFoundException();   // missing or invalid
        if ($this->state->product === null || $newId !== $oldId) {      // passing through is not reloading
            $this->state->product = $this->productService->loadProductById($newId);
            $this->update();
        }
        if ($initialization) {
            $this->state->errorCode = 0;
            $this->state->errorMessage = null;
            $this->view = (self::$createView ?? throw new LogicException('ProductPresenter::$createView not set'))($this);
        }
        $this->ownerSlot = $keys->ownerSlot;                                   // every trip
        $this->ownerSlot?->setView($this->view);

        return true;
    }

    public function publishParameters(CubeIntent $intent): void
    {
        if ($this->state->product !== null) {
            $keys = new ProductKeys($this->app, $intent);
            $keys->productId = $this->state->product->id;
        }
    }

    public function onAddToCart(?int $quantity): void
    {
        try {
            if ($quantity === null || $quantity < 1) {
                $this->alert(2, 'A quantidade deve ser maior ou igual a 1.');   // expected: own state
                return;
            }
            $this->app->cart()?->addProduct($this->state->product ?? throw new LogicException('No product'), $quantity);
            CartKeys::of($this->app)->go();
        } catch (Throwable $caught) {
            $this->app->alertUnexpectedError('Adding an item to cart', $caught);   // unexpected: the alert
        }
    }

    public function skeleton(): CubeSkeleton
    {
        return new Skeleton('48b693f67410', function (int $event, int $qtde, array $form): void {
            match ($event) {
                self::EVENT_OPEN_PRODUCTS => $this->onOpenProducts(),
                self::EVENT_ADD_TO_CART => $this->onAddToCart(Coerce::asInt($form['p.quantity'] ?? null)),
                default => Skeleton::unknownEvent($event),
            };
        });
    }

    private function alert(int $code, string $message): void
    {
        $this->state->errorCode = $code;
        $this->state->errorMessage = $message;
        $this->update();
    }
}
```

Wiring the view factory - in BOTH places:

```php
// RemoteHost/ShoppingRemoteApplication::installViewFactories()
ProductPresenter::$createView = static fn (ProductPresenter $p) => new RemoteView(self::of($p->app), $p, $p->state, $p->skeleton());

// tests/.../Mock/ShoppingApplicationMock::installViewMocks()
ProductPresenter::$createView = static fn (ProductPresenter $p) => new ProductViewMock($p);
```

```php
// tests/.../Mock/ProductViewMock.php
final class ProductViewMock extends AbstractViewMock
{
    public readonly ProductViewState $state;

    public function __construct(public readonly ProductPresenter $presenter)
    {
        parent::__construct();
        $this->state = $presenter->state;      // the SAME object the presenter changes
    }
}
```

## 3. The React view and its registration

```tsx
// frontend/shopping.shell.react/src/scripts/views/product/ProductView.tsx
const ON_OPEN_PRODUCTS = 1          // the SAME codes as the skeleton
const ON_ADD_TO_CART = 2

export type ProductViewState = {
  product?: Product                 // may be omitted (null on the server)
  errorMessage?: string             // omitted when blank
}

class ProductViewClass extends BaseViewClass<ViewProps, ProductViewState> {
  override render({ className }: ViewProps) {
    const product = this.state.product ?? DefaultProduct   // default what can be omitted
    return (/* ... */)
  }

  readonly emitAddToCart = () => {
    const { vsid, state } = this
    bridge.setFormField(vsid, "p.quantity", state.quantity)   // event argument: "p." prefix
    bridge.submit(vsid, ON_ADD_TO_CART)
  }
}

export default BaseViewClass.FC(ProductViewClass, "48b693f67410")   // the SAME classId as the skeleton
```

```tsx
// frontend/shopping.shell.react/src/scripts/index.tsx
bridge.registerComponents(/* ... */, ProductView /* , ... */)
```

A slot in the view: the owner's state has `contentViewId?: string`, drawn with
`bridge.createView(state.contentViewId)`. Then `bin/build-frontend.sh`.

The view only draws. Formatting, defaults and rules that another view would write the same
way belong in the presenter (or the info struct).

## 4. GUARD presenter

```php
/** Guard of the restricted area (no view). Without a logged user, back to the public area. */
final class RestrictedPresenter implements CubePresenter
{
    public function __construct(public readonly ShoppingApplication $app)
    {
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        if ($this->app->subject() === null) {
            (new OpenKeys($this->app, $intent))->go();  // redirect WITH the intent in transit
            return false;
        }
        if ($deepest) {                                // the branch alone is not a screen
            (new HomeKeys($this->app, $intent))->go();
            return false;
        }

        return true;
    }

    public function publishParameters(CubeIntent $intent): void {}
    public function commitComputedState(): void {}
    public function release(): void {}
}
```

Whatever is outside the guarded branch is public by construction.

## 5. SLOT OWNER presenter

```php
final class HomePresenter extends AbstractCubePresenter
{
    private readonly CubeViewSlot $contentSlot;
    private ?CubeViewSlot $ownerSlot = null;

    public function __construct(ShoppingApplication $app)
    {
        parent::__construct($app);
        $this->state = new HomeViewState();
        $this->contentSlot = new CubeViewSlot($this->setContentView(...));
    }

    public function applyParameters(CubeIntent $intent, bool $initialization, bool $deepest): bool
    {
        if ($initialization) {
            $this->view = (self::$createView ?? throw new LogicException('...'))($this);
            // create children, listeners... ONCE
        }
        $keys = new RootKeys($this->app, $intent);
        $this->ownerSlot = $keys->ownerSlot;
        $this->ownerSlot?->setView($this->view);
        if ($deepest) {
            $this->setContentView(null);                       // the home itself
        } else {
            $keys->ownerSlot = $this->contentSlot;             // re-offer, same name
        }

        return true;
    }

    public function release(): void
    {
        $this->state->contentView = null;
        // release children, remove listeners, cancel timers
        parent::release();
    }

    private function setContentView(?CubeView $view): void
    {
        if ($this->state->contentView !== $view) {
            $this->state->contentView = $view;
            $this->update();
        }
    }
}
```

## 6. CHILD segment presenter

```php
/** @extends AbstractChildPresenter<ShoppingApplication> */
final class ProductsPanelPresenter extends AbstractChildPresenter
{
    /** @var (Closure(self): CubeView)|null */
    public static ?Closure $createView = null;

    public readonly ProductsPanelViewState $state;

    public function __construct(ShoppingApplication $app, public readonly HomePresenter $owner)
    {
        parent::__construct($app);
        $this->state = new ProductsPanelViewState();
    }

    public function onOpenProduct(?int $productId): void
    {
        $this->owner->onOpenProduct($productId);       // navigation belongs to the owner
    }

    protected function onCreateView(): CubeView
    {
        return (self::$createView ?? throw new LogicException('...'))($this);
    }

    protected function onInitialize(): void
    {
        $this->loadProducts();
    }
}

// in the owner, once:
$this->productsPanel = new ProductsPanelPresenter($this->app, $this);
$this->state->productsPanelView = $this->productsPanel->initialize();
// in the owner's release():
$this->productsPanel?->release();
$this->state->productsPanelView = null;
```

If a segment's state must survive F5 (the open tab, the page), it is a PARAMETER OF THE
OWNER: the owner reads it in `applyParameters`, passes it to the child, and publishes it.

## 7. Taking the user to another screen

```php
public function onOpenReceipt(?int $purchaseId): void
{
    try {
        if ($purchaseId === null) {
            $this->alert(7, 'Código do recibo é obrigatório.');
            return;
        }
        $receipt = ReceiptKeys::of($this->app);       // from the current state
        $receipt->purchaseId = $purchaseId;
        $receipt->purchaseMade = true;                // attribute: not in the address
        $receipt->go();
    } catch (PurchaseNotFoundException) {
        $this->alert(5, 'Recibo não localizado.');                         // the screen stays here
    } catch (Throwable $caught) {
        $this->app->alertUnexpectedError("Opening receipt {$purchaseId}", $caught);
    }
}
```

## 8. Changing your own state (no navigation)

```php
public function onPageChange(int $page): void
{
    $this->page = max(0, $page);
    $this->state->rows = $this->service->loadPage($this->page);
    $this->update();
    $this->app->updateHistory();     // only if the page is published in the address
}
```

## 9. Timers and listeners

```php
private ?Registration $pendingErrorClear = null;

private function alert(int $code, string $message): void
{
    $this->state->errorCode = $code;
    $this->state->errorMessage = $message;
    $this->update();
    $this->pendingErrorClear?->remove();
    $this->pendingErrorClear = $this->app->scheduler()->schedule(function (): void {
        $this->pendingErrorClear = null;
        $this->state->errorCode = 0;
        $this->state->errorMessage = null;
        $this->update();                       // sent by the background flush
    }, 3.0);
}

public function release(): void
{
    $this->pendingErrorClear?->remove();       // a timer must not outlive its presenter
    $this->pendingErrorClear = null;
    parent::release();
}
```

Listeners follow the same rule: keep the `Registration` returned by
`addChangeListener(...)` and `remove()` it in `release()`.

## 10. Remembering a reader preference

Preferences do not go in the address (a shared link would carry them). Add the key to
`StorageKeys`:

```php
$store = $this->app->clientPersistentStore();
$this->collapsed = $store->get(StorageKeys::PRODUCTS_COLLAPSED) === 'true';   // in applyParameters
$store->set(StorageKeys::PRODUCTS_COLLAPSED, $collapsed ? 'true' : 'false');   // in the event handler
```

Use `->secure()` for anything sensitive (tokens).

## 11. Testing without a browser

```php
final class ProductFlowTest extends PresentationTestCase
{
    #[Test]
    public function addingToCart_opensTheCart(): void
    {
        $home = $this->login();                                     // real login, home view mock
        $home->presenter->onOpenProduct(Seed::PEN_DRIVE2GB_ID);     // what the event would call

        $product = ProductViewMock::cast($home->state->contentView);
        self::assertSame(Seed::PEN_DRIVE2GB_ID, $product->state->product?->id);

        $product->presenter->onAddToCart(2);
        $cart = CartViewMock::cast($home->state->contentView);
        self::assertSame(0, $cart->state->errorCode);
        self::assertCount(1, $cart->state->items);
    }

    #[Test]
    public function unknownProduct_keepsTheHome_andShowsTheError(): void
    {
        $home = $this->login();
        $home->presenter->onOpenProduct(PHP_INT_MIN);

        self::assertSame(3, $home->state->errorCode);              // rollback: still on the home
        self::assertNull($home->state->contentView);
    }
}
```

- Timers: `$this->scheduler` is a `ManualScheduler`; `$this->scheduler->advance(3.0)` fires what is due.
- Address: `$this->app->historyUpdates` counts `updateHistory()`; `$this->app->newIntent()`
  shows what would be published.
- Concurrency (two sessions, slow queries): see `tests/Framework/Persistence/DatabaseConcurrencyTest`.
