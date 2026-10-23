# Implementing views in the React shell

This guide explains how to write a presenter's React view: the component that draws the state the
server sends and sends clicks back as events. The shell lives in
`frontend/shopping.shell.react`; the protocol client (WebSocket, states, forms) in
`frontend/remote.bridge.react`, re-exported by the shell as `@root/bridge` (`src/scripts/bridge/index.tsx`).

The running example is the product **reviews panel** (`ReviewsPanel.tsx`), the view of the
`ReviewsPanelPresenter` described in [presentation-module.md](presentation-module.md). It is the
exercise of the guides: the code has been tested, but it is not in the repository - you will
build it.

The pattern uses **classes** instead of the usual React Hooks. It looks odd at first, but there
is a clear reason, explained right below.

---

## 1. Why classes instead of Hooks?

In a typical React app, each component keeps its state with `useState`. Here **the screen state
belongs to the server**: the presenter changes the `ViewState`, the server serializes it and the
bridge hands the new JSON to the component. The component decides nothing - it draws.

For that, the component needs a **stable instance** that survives re-renders, with access to the
latest state from the server and, at the same time, room for the little state that belongs only
to the UI ("what the user has typed but not sent yet"). A class gives you that for free:

| Member | What it is | Owned by |
|---|---|---|
| `this.state` | the `ViewState` serialized by the server | the server (read it, don't store anything in it) |
| `this.vsid` | the id of this view instance (`"<classId>:<n>"`) | the server |
| class fields (`private rating = 0`) | local UI state | the view |
| `this.forceUpdate()` | redraws after changing a local field | - |

Under the hood, `BaseViewClass.FC(...)` (in `src/scripts/utils/ViewUtils.tsx`) generates a
function component that creates the instance **once** (`React.useMemo` with no dependencies),
binds it to the view's state in the bridge (`bridge.bindView`) and calls `render()` on every
update. You only write the class.

---

## 2. The two kinds of class

| Base class | When to use | Examples |
|---|---|---|
| `BaseViewClass<P, S>` | a presenter's view: it has a `classId`, receives `state` and `vsid` from the server | `ProductView`, `HomeView`, `ProductsPanel`, `ReviewsPanel` |
| `BasePanelClass<P>` | a piece of the screen with no state of its own on the server: it only receives `props` | `HeaderPanel`, `ContentPanel`, the showcase's `ProductCard` |

Both live in `@root/utils/ViewUtils`. A sub-component that doesn't even need an instance (no
handlers, no fields) can be a plain function, like the reviews panel's `ReviewItem`. `SlotView`
is a special case: a function component that calls `bridge.bindView` directly.

---

## 3. Structure of a main view

The skeleton of the reviews panel, with the parts every view repeats:

```tsx
// frontend/shopping.shell.react/src/scripts/views/product/ReviewsPanel.tsx
import React, { ReactNode } from "react"
import clsx from "clsx"
import bridge, { type ViewProps } from "@root/bridge"
import { BaseViewClass } from "@root/utils/ViewUtils"
import { Button } from "@root/swc"
import * as DateUtils from "@root/utils/DateUtils"
import Sel from "./reviews-sel"

// :: Actions (the SAME codes as the ReviewsPanelPresenter skeleton)

const ON_SUBMIT_REVIEW = 1

const MAX_COMMENT_LENGTH = 500

// :: Types

type Review = {
  id: number
  author?: string
  rating: number
  comment?: string
  createdAt: number // milliseconds since 1970
}

type ReviewsPanelState = {
  reviews?: Review[]
  count: number
  averageLabel?: string // omitted when there are no reviews
  errorCode: number
  errorMessage?: string
}

// :: View

class ReviewsPanelClass extends BaseViewClass<ViewProps, ReviewsPanelState> {
  // the form is local: it only goes to the server on submit, as event arguments (p.*)
  private rating = 0
  private comment = ""
  private lastCount = -1

  override render({ className }: ViewProps): ReactNode {
    const { state } = this
    const reviews = state.reviews ?? []
    const count = state.count ?? 0
    // ... draws the header, list, form and error (section 12)
  }

  // :: Handlers

  private readonly onRatingChange = (e: React.ChangeEvent<HTMLSelectElement>) => { /* 3.4 */ }
  private readonly onCommentChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => { /* same */ }

  // :: Emissors

  readonly emitSubmitReview = () => { /* 3.6 */ }
}

export default BaseViewClass.FC(ReviewsPanelClass, "8cc32236e32a") // the SAME classId as the skeleton
```

### 3.1 `BaseViewClass.FC(View, classId)`: the link to the presenter

The second argument is the `classId` - **the same text** as the presenter's `Skeleton`:

```php
return new Skeleton('8cc32236e32a', function (int $event, int $count, array $form): void { /* ... */ });
```

The server sends each state with `"#": "8cc32236e32a:17"` (classId + instance number). To draw,
the bridge splits off the prefix before the `:` and looks for the component registered with that
`VIEW_ID` (`FC` stores the `classId` in `View.VIEW_ID`). If the id differs, or the view was not
registered in `index.tsx`, the bridge throws `No view registered for viewId: "..."` and the area
stays empty - check the browser console.

### 3.2 The state type: optional fields

The TypeScript type mirrors the PHP `ViewState`, following the serializer's rules
(`src/Framework/Cube/Remote/ViewStateSerializer.php`):

| In PHP | Arrives in React | In the TS type |
|---|---|---|
| `public int $count = 0` (non-nullable) | always, including `0` | `count: number` |
| `public ?string $errorMessage = null` | omitted when `null` | `errorMessage?: string` |
| `public string $averageLabel = ''` | omitted when blank | `averageLabel?: string` |
| `public ?CubeView $reviewsPanelView` | `reviewsPanelViewId: "classId:n"` | `reviewsPanelViewId?: string` |
| `public array $reviews` (list of `ReviewInfo`) | array with the public fields of each item | `reviews?: Review[]` |
| object with private fields (an entity) | `{}` | - don't do this |

Mark as optional everything that may be missing and **give it a default when reading**:
`state.reviews ?? []`, `state.count ?? 0`. Before the first response, the state may be empty.
Also remember that the server already sends values ready to display ("4.5 out of 5"): formatting
and rules that another view would write the same way belong to the presenter.

### 3.3 `this.state` belongs to the server

On every new state, the bridge **deletes all properties** of the state object and copies the
ones that arrived (`ViewScope.setState`). The object is the same, its content is replaced.
Consequence: anything the view writes into `this.state` disappears on that view's next update.
Local state goes in **class fields**, like the panel's `rating` and `comment`.

> The existing `ProductView` keeps the quantity in `state.quantity` and syncs it with
> `bridge.setFormField(vsid, "quantity", ...)`. That works as long as the server does not resend
> the product state, but the value goes back to 1 if it does - and the `"quantity"` field
> (without `p.`) is discarded on the server, because `ProductPresenter` has no `syncState`. For
> new code, prefer class fields.

### 3.4 Class fields and `forceUpdate()`

```tsx
private rating = 0
private comment = ""

private readonly onRatingChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
  this.rating = Number(e.target.value)
  this.forceUpdate()
}
```

When you change a field, call `this.forceUpdate()`: nothing observes the fields, just as nothing
observes the `ViewState` on the server.

### 3.5 `render(props, initial)`

`render` receives a second parameter, `initial`, which is `true` only on the instance's first
render. It is for setting something up once - `PurchasesPanel` registers there
(`if (initial && !this.resizeHandler) { ... }`) the `resize` listener that computes how many
purchases fit on the screen and sends the result to the server as `p.capacity`.

### 3.6 Emitters: sending events to the server

```tsx
readonly emitSubmitReview = () => {
  const { vsid } = this
  bridge.setFormField(vsid, "p.rating", this.rating > 0 ? this.rating : null)
  bridge.setFormField(vsid, "p.comment", this.comment)
  bridge.submit(vsid, ON_SUBMIT_REVIEW)
}
```

- `bridge.setFormField(vsid, name, value)` accumulates a field in this view's form;
  `bridge.submit(vsid, code)` sends the accumulated form together with the event and turns on the
  `BrowserView` "sending" bar until the response. `bridge.submitSilent(...)` sends without the
  bar.
- **The event code is a contract.** Every `submit(vsid, N)` needs an `N => ...` arm in the
  skeleton's `match`; a code with no arm reaches `Skeleton::unknownEvent`, which throws
  `eventCode(N) not handled` and shows the unexpected-error alert. Keep the codes as constants at
  the top of the file (`// :: Actions`), with the same numbers as in PHP.
- **Event arguments carry the `p.` prefix.** In PHP they arrive in `$form['p.rating']`. A field
  **without** the prefix is state synchronization: it goes to the third argument of `Skeleton`
  (`syncState`) and, if the presenter has none, it is silently discarded.
- **Send the minimum**: the product id, not the product. The review author is not even sent - the
  server knows who is logged in.
- **Sensitive data** is encrypted before it leaves: the login does
  `bridge.setFormField(vsid, "p.password", await bridge.cipher(password))`.

Emitters and handlers are **`readonly` fields holding an arrow function**, not methods. The arrow
function captures `this`; as a field, the reference stays the same for the instance's whole life
(passing `this.emitX` to `onClick` does not create a new function on every render); `readonly`
prevents accidental reassignment.

### 3.7 The local form and the success signal

The panel's rating and comment live **only in React** until submission. The server does not say
"it worked": it reloads the list, and the count changes. The view uses that as the signal to
clear the form:

```tsx
// the count changed ⇒ the server accepted the review (or switched products): clear the form
if (count !== this.lastCount) {
  this.lastCount = count
  this.rating = 0
  this.comment = ""
}
```

If validation fails, the count does not change, the form stays as it was and the state's
`errorMessage` shows up. If your screen needs an explicit signal, put a field in the `ViewState`
(for example, a counter of accepted submissions) instead of inferring it.

---

## 4. Internal file layout

The shell's views follow the same order, marked with `// ::` comments:

| Section | Content |
|---|---|
| `// :: Actions` | event code constants (`ON_SUBMIT_REVIEW = 1`) |
| `// :: Types` | the type of the state and its items; defaults (`DefaultProduct`) |
| `// :: View` | the class: local fields, `render`, helper renderers |
| `// :: Handlers` | react to UI events and change local fields (+ `forceUpdate`) |
| `// :: Emissors` | `emitX`: build the form and call `bridge.submit` |
| `// :: Internal` | the file's own sub-components and functions (`ReviewItem`, `stars`) |

The distinction between **handler** and **emitter** helps reading: a handler stays in the
browser; an emitter talks to the server.

---

## 5. Sub-components with `BasePanelClass`

When a piece of the screen has its own handlers, extract a `BasePanelClass`. It has no `classId`
and no server state: it receives everything through `props`, including the owner view's `vsid`
when it needs to emit events on its behalf. The showcase's product card:

```tsx
// views/home/ProductsPanel.tsx
type ProductCardProps = {
  vsid: string
  product: Product
}

class ProductCardClass extends BasePanelClass<ProductCardProps> {
  vsid!: string
  product!: Product

  override render({ vsid, product }: ProductCardProps): React.ReactNode {
    this.vsid = vsid
    this.product = product
    return <div className={Sel.productCard} onClick={this.emitOpenProduct}>{/* ... */}</div>
  }

  emitOpenProduct = () => {
    const { vsid, product } = this
    bridge.setFormField(vsid, "p.productId", product.id)
    bridge.submit(vsid, ON_OPEN_PRODUCT)
  }
}

const ProductCard = BasePanelClass.FC(ProductCardClass)
```

`render` copies the props into fields, because the emitter (created once) needs the **current**
values. The event goes out with the `ProductsPanel`'s `vsid` and reaches the skeleton of
`ProductsPanelPresenter` (`1 => $this->onOpenProduct(Coerce::asInt($form['p.productId'] ?? null))`).

---

## 6. Drawing a child's or a slot's view

When the presenter's `ViewState` has a `?CubeView`, React receives the child view's id and draws
it with `bridge.createView`:

```tsx
// views/product/ProductView.tsx
export type ProductViewState = {
  // ...
  reviewsPanelViewId?: string // the child presenter's view (ReviewsPanelPresenter)
}

// in render, after the error block:
{bridge.createView(state.reviewsPanelViewId)}
```

`createView` returns `null` when the id is missing, so no `if` is needed. The same mechanism
draws the home slot - `bridge.createView(state.contentViewId)` shows the product, the cart or the
receipt - and the two panels (`productsPanelViewId`, `purchasesPanelViewId`). The owner view does
not import the child's component: the bridge picks it, by the `classId` in the id.

---

## 7. CSS and styles

### 7.1 The `*-sel.ts` + `.scss` pair

Each view module has a **selectors** file and an SCSS sheet. The view imports only the
selectors; the `-sel.ts` imports the `.scss`:

```ts
// views/product/reviews-sel.ts
import GlobalSel from "@root/global-sel"

import "./reviews.scss"

const ReviewsSel = {
  ...GlobalSel,

  // :: Reviews panel

  panel: "wdc-reviews",
  header: "wdc-reviews__header",
  title: "wdc-reviews__title",
  average: "wdc-reviews__average",
  // ...
} as const

export default ReviewsSel
```

```scss
// views/product/reviews.scss
.wdc-reviews {
  background: var(--app-surface);
  border: 1px solid var(--app-border);
  border-radius: var(--app-radius);

  &__header { display: flex; align-items: baseline; gap: 12px; }
  // ...
}
```

- **Never write the CSS class name directly in the view.** `className={Sel.header}` gives you
  autocompletion and an editor error when the selector does not exist; `"wdc-reviews__header"`
  typed by hand breaks silently.
- **BEM names**, with the `wdc-` prefix and the module name: `wdc-reviews__item-header`.
- **`...GlobalSel`** brings in the global utilities (`src/scripts/global-sel.ts`, implemented in
  `src/scss/_utilities.scss`): layout (`flexCol`, `gap16`), spacing (`mt16`, `mr4`), text, and
  ready-made blocks like `alertError`/`alertErrorIcon`/`alertErrorText`, which the panel uses for
  the error message.
- **Color and shape tokens** are CSS variables in `src/scss/app.scss`: `--app-accent`,
  `--app-surface`, `--app-text`, `--app-text-secondary`, `--app-border`, `--app-radius`,
  `--app-radius-sm`, the `--app-shadow-*` shadows. Use them instead of hard-coded colors.

### 7.2 Combining classes with `clsx`

```tsx
<div className={clsx(Sel.panel, className)}>
<i className={clsx("bi bi-star", Sel.mr4)}></i>
```

`clsx` joins classes and ignores falsy values (`clsx(Sel.tabItem, active && Sel.tabItemActive)`).
Pass the `className` that came in the props on to the root element: the owner may want to
position the view.

### 7.3 Visual components

The shell uses Spectrum Web Components through React wrappers, re-exported in `src/scripts/swc.ts`:
`Theme`, `Button`, `ActionButton`, `Textfield`, `FieldLabel`, `Divider`, `ProgressCircle`.
Import from `@root/swc`, not from the package:

```tsx
import { Button } from "@root/swc"

<Button variant="accent" size="m" onClick={this.emitSubmitReview}>
  <i className={clsx("bi bi-star", Sel.mr4)}></i>
  Submit review
</Button>
```

Some older views bind the click through a `ref` (`ref={this.btnRef}` + `addEventListener("click",
...)`), like `HeaderPanel` and `LoginView`; `ProductView` and `ReviewsPanel` use `onClick`
directly. Plain HTML elements (`<select>`, `<textarea>`) work too, styled by the module's `.scss`.

---

## 8. Images and assets

- **Icons**: the Bootstrap Icons font is injected into the `index.html` generated by
  `scripts/inject-externals.js`. Use `bi bi-<name>` classes (`bi bi-bag-plus`, `bi bi-star`).
- **Data images** (the product photo) come from the server: `EndpointUtils.productImagePath(id)`
  builds `./image/product/<id>.png`, served by the PHP `ProductImageHandler`. If your screen needs
  another image per record, create the route on the server and a helper in `EndpointUtils`.
- **Small static artwork** becomes an inline SVG component, like `views/home/ShoppingLogo.tsx`.

---

## 9. File structure of a view module

```text
frontend/shopping.shell.react/src/scripts/views/product/
  ProductView.tsx       the ProductPresenter view (classId 48b693f67410)
  product-sel.ts        product selectors (imports product.scss)
  product.scss
  ReviewsPanel.tsx      the ReviewsPanelPresenter view (classId 8cc32236e32a)
  reviews-sel.ts        panel selectors (imports reviews.scss)
  reviews.scss
```

One `.tsx` file per registered view, with an `export default` of the `FC`. Sub-components used
only by that view stay in the same file (`// :: Internal`); panels reused by several views get a
file of their own (`HeaderPanel.tsx`). The `-sel.ts`/`.scss` pair can belong to the whole module
(`home-sel.ts` serves `HomeView`, `ProductsPanel` and `PurchasesPanel`) or to a single view.

---

## 10. Registering in `index.tsx`

Every view with a `classId` must be registered, otherwise the bridge does not know how to draw
it:

```tsx
// frontend/shopping.shell.react/src/scripts/index.tsx
import ProductView from "./views/product/ProductView"
import ReviewsPanel from "./views/product/ReviewsPanel"
// ...

bridge.registerComponents(
  BrowserView,
  SlotView,
  RootView,
  // ...
  ProductView,
  ReviewsPanel,
  ProductsPanel,
  PurchasesPanel,
)
```

`registerComponents` reads each component's `VIEW_ID` (stored by `BaseViewClass.FC`).
`BasePanelClass` panels are not registered: they are regular components, used by whoever imports
them.

---

## 11. Building

```bash
bin/build-frontend.sh                                   # full build
(cd frontend/shopping.shell.react && npm run watch)     # development: rebuilds on every change
```

`bin/build-frontend.sh` calls `frontend/shopping.shell.react/build.sh`, which installs the
dependencies the first time (shell and bridge) and runs `npm run build:clean`. The output goes to
`work/frontend/shopping.shell.react`, from where the PHP server serves it - no need to restart
the server, just reload the browser. Environment details in [setup-local.md](setup-local.md).

> **The build does not check types.** Parcel transpiles TypeScript with SWC, which **strips** the
> types without checking them: a misnamed field in the state type, or a prop that doesn't exist,
> compiles and only shows up as `undefined` in the browser. Trust the errors the editor shows (it
> uses the shell's `tsconfig.json`) and check the state type against the PHP `ViewState`.

---

## 12. Complete example - `ReviewsPanel.tsx`

The `render` left out of section 3, and the internal sub-components:

```tsx
override render({ className }: ViewProps): ReactNode {
  const { state } = this
  const reviews = state.reviews ?? []
  const count = state.count ?? 0

  // the count changed ⇒ the server accepted the review (or switched products): clear the form
  if (count !== this.lastCount) {
    this.lastCount = count
    this.rating = 0
    this.comment = ""
  }

  return (
    <div className={clsx(Sel.panel, className)}>
      <div className={Sel.header}>
        <h6 className={Sel.title}>Reviews</h6>
        {state.averageLabel && <span className={Sel.average}>{state.averageLabel}</span>}
        <span className={Sel.count}>{count === 1 ? "1 review" : `${count} reviews`}</span>
      </div>

      {reviews.length === 0 ? (
        <div className={Sel.empty}>This product has not been reviewed yet. Be the first!</div>
      ) : (
        <div className={Sel.list}>
          {reviews.map((review) => (
            <ReviewItem key={review.id} review={review} />
          ))}
        </div>
      )}

      <div className={Sel.form}>
        <div className={Sel.formRow}>
          <label htmlFor="review-rating">Your rating:</label>
          <select id="review-rating" className={Sel.select} value={this.rating} onChange={this.onRatingChange}>
            <option value={0}>Choose...</option>
            {[5, 4, 3, 2, 1].map((n) => (
              <option key={n} value={n}>
                {stars(n)} ({n})
              </option>
            ))}
          </select>
        </div>
        <textarea
          className={Sel.textarea}
          placeholder="Tell us what you thought of the product (optional)"
          maxLength={MAX_COMMENT_LENGTH}
          value={this.comment}
          onChange={this.onCommentChange}
        />
        <div className={Sel.formRow}>
          <span className={Sel.charCount}>
            {this.comment.length}/{MAX_COMMENT_LENGTH}
          </span>
          <Button variant="accent" size="m" onClick={this.emitSubmitReview}>
            <i className={clsx("bi bi-star", Sel.mr4)}></i>
            Submit review
          </Button>
        </div>
      </div>

      {state.errorMessage && (
        <div className={clsx(Sel.alertError, Sel.mt16)}>
          <span className={Sel.alertErrorIcon}><i className="bi bi-exclamation-circle"></i></span>
          <span className={Sel.alertErrorText}>{state.errorMessage}</span>
        </div>
      )}
    </div>
  )
}
```

```tsx
// :: Internal

function stars(rating: number) {
  const n = Math.max(0, Math.min(5, rating))
  return "★".repeat(n) + "☆".repeat(5 - n)
}

function ReviewItem({ review }: { review: Review }) {
  return (
    <div className={Sel.item}>
      <div className={Sel.itemHeader}>
        <span className={Sel.author}>{review.author || "Anonymous"}</span>
        <span className={Sel.stars} title={`${review.rating} out of 5`}>
          {stars(review.rating)}
        </span>
        <span className={Sel.date}>{DateUtils.formatDate(review.createdAt)}</span>
      </div>
      {review.comment && <p className={Sel.comment}>{review.comment}</p>}
    </div>
  )
}
```

Notice:

- `maxLength` on the `<textarea>` is a **help**, not a rule: the server validates again (500
  characters) and returns the error in the state if someone goes over it.
- Rating 0 ("Choose...") is sent as `null`; the server replies "Choose a rating from 1 to 5." in
  `errorMessage`.
- `createdAt` arrives in milliseconds and `DateUtils.formatDate` formats it in the browser's time
  zone.
- `review.author || "Anonymous"` and `review.comment &&` cover both a missing field and `""`.

For the panel to show up, two lines outside this file are still missing: the
`{bridge.createView(state.reviewsPanelViewId)}` in `ProductView` (section 6) and `ReviewsPanel` in
`registerComponents` (section 10).

---

## 13. Checklist when creating a view

- [ ] File `views/<module>/XxxView.tsx`, class `extends BaseViewClass<ViewProps, XxxState>`.
- [ ] `export default BaseViewClass.FC(XxxClass, "<classId>")` with the **same** `classId` as the
      presenter's `Skeleton`.
- [ ] State type mirroring the `ViewState`: optional for everything that can be `null` or a blank
      string; `CubeView` as `xxxViewId?: string`; a default (`??`) on every read.
- [ ] Nothing stored in `this.state`; local state in class fields + `forceUpdate()`.
- [ ] Event codes as constants in `// :: Actions`, equal to those in the skeleton's `match` - one
      arm for each code.
- [ ] Arguments with `bridge.setFormField(vsid, "p.<name>", value)` before `bridge.submit`; nothing
      without `p.` unless the presenter has `syncState`.
- [ ] Handlers and emitters as `readonly` fields with an arrow function.
- [ ] Child views and slots with `bridge.createView(state.xxxViewId)`.
- [ ] A `xxx-sel.ts` (with `...GlobalSel`) + `xxx.scss` pair in BEM `wdc-<module>__...`; no CSS
      class typed in the view; colors through the `--app-*` variables.
- [ ] Visual components from `@root/swc`; `bi bi-*` icons.
- [ ] Registered in `bridge.registerComponents(...)` in `index.tsx`.
- [ ] No type errors in the editor (the build does not check); `bin/build-frontend.sh` or
      `npm run watch`; test in the browser.
- [ ] The server side: [presentation-module.md](presentation-module.md). The full recipe:
      [building-a-feature.md](building-a-feature.md).
