# Implementando views no shell React

Este guia explica como escrever a view React de um presenter: o componente que desenha o estado
que o servidor manda e devolve os cliques como eventos. O shell fica em
`frontend/shopping.shell.react`; o cliente do protocolo (WebSocket, estados, formulários) em
`frontend/remote.bridge.react`, reexportado pelo shell em `@root/bridge` (`src/scripts/bridge/index.tsx`).

O exemplo condutor é o **painel de avaliações** do produto (`ReviewsPanel.tsx`), a view do
`ReviewsPanelPresenter` descrito em [presentation-module.md](presentation-module.md). Ele é o
exercício dos guias: o código foi testado, mas não está no repositório - você vai montá-lo.

O padrão usa **classes** em vez dos Hooks habituais do React. Parece estranho no começo, mas
tem uma razão clara, explicada logo abaixo.

---

## 1. Por que classes em vez de Hooks?

Num app React comum, cada componente guarda o seu estado com `useState`. Aqui **o estado da
tela é do servidor**: o presenter muda o `ViewState`, o servidor serializa e o bridge entrega o
JSON novo ao componente. O componente não decide nada - desenha.

Para isso o componente precisa de uma **instância estável**, que sobreviva às re-renderizações,
com acesso ao estado mais recente vindo do servidor e, ao mesmo tempo, com espaço para o pouco
de estado que é só da interface ("o que o usuário já digitou e ainda não enviou"). Uma classe
dá isso de graça:

| Membro | O que é | De quem |
|---|---|---|
| `this.state` | o `ViewState` serializado pelo servidor | do servidor (leia, não guarde nada nele) |
| `this.vsid` | o id desta instância de view (`"<classId>:<n>"`) | do servidor |
| campos da classe (`private rating = 0`) | estado local da interface | da view |
| `this.forceUpdate()` | redesenha depois de mudar um campo local | - |

Por baixo, `BaseViewClass.FC(...)` (em `src/scripts/utils/ViewUtils.tsx`) gera um componente
funcional que cria a instância **uma vez** (`React.useMemo` sem dependências), liga-a ao estado da
view no bridge (`bridge.bindView`) e chama `render()` a cada atualização. Você escreve só a
classe.

---

## 2. Os dois tipos de classe

| Classe base | Quando usar | Exemplos |
|---|---|---|
| `BaseViewClass<P, S>` | a view de um presenter: tem `classId`, recebe `state` e `vsid` do servidor | `ProductView`, `HomeView`, `ProductsPanel`, `ReviewsPanel` |
| `BasePanelClass<P>` | um pedaço da tela sem estado próprio no servidor: só recebe `props` | `HeaderPanel`, `ContentPanel`, o `CardProduto` da vitrine |

Ambas vivem em `@root/utils/ViewUtils`. Um sub-componente que nem precisa de instância (sem
handlers, sem campos) pode ser uma função comum, como o `ReviewItem` do painel de avaliações.
A `SlotView` é um caso à parte: um componente funcional que chama `bridge.bindView` direto.

---

## 3. Estrutura de uma view principal

O esqueleto do painel de avaliações, com as partes que se repetem em toda view:

```tsx
// frontend/shopping.shell.react/src/scripts/views/product/ReviewsPanel.tsx
import React, { ReactNode } from "react"
import clsx from "clsx"
import bridge, { type ViewProps } from "@root/bridge"
import { BaseViewClass } from "@root/utils/ViewUtils"
import { Button } from "@root/swc"
import * as DateUtils from "@root/utils/DateUtils"
import Sel from "./reviews-sel"

// :: Actions (os MESMOS códigos do skeleton do ReviewsPanelPresenter)

const ON_SUBMIT_REVIEW = 1

const MAX_COMMENT_LENGTH = 500

// :: Types

type Review = {
  id: number
  author?: string
  rating: number
  comment?: string
  createdAt: number // milissegundos desde 1970
}

type ReviewsPanelState = {
  reviews?: Review[]
  count: number
  averageLabel?: string // omitido quando não há avaliações
  errorCode: number
  errorMessage?: string
}

// :: View

class ReviewsPanelClass extends BaseViewClass<ViewProps, ReviewsPanelState> {
  // o formulário é local: só vai ao servidor no envio, como argumento do evento (p.*)
  private rating = 0
  private comment = ""
  private lastCount = -1

  override render({ className }: ViewProps): ReactNode {
    const { state } = this
    const reviews = state.reviews ?? []
    const count = state.count ?? 0
    // ... desenha cabeçalho, lista, formulário e erro (seção 9)
  }

  // :: Handlers

  private readonly onRatingChange = (e: React.ChangeEvent<HTMLSelectElement>) => { /* 3.4 */ }
  private readonly onCommentChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => { /* idem */ }

  // :: Emissors

  readonly emitSubmitReview = () => { /* 3.6 */ }
}

export default BaseViewClass.FC(ReviewsPanelClass, "8cc32236e32a") // o MESMO classId do skeleton
```

### 3.1 `BaseViewClass.FC(View, classId)`: o elo com o presenter

O segundo argumento é o `classId` - **o mesmo texto** do `Skeleton` do presenter:

```php
return new Skeleton('8cc32236e32a', function (int $event, int $qtde, array $form): void { /* ... */ });
```

O servidor manda cada estado com `"#": "8cc32236e32a:17"` (classId + número da instância). Para
desenhar, o bridge separa o prefixo antes do `:` e procura o componente registrado com aquele
`VIEW_ID` (`FC` grava o `classId` em `View.VIEW_ID`). Se o id diverge, ou a view não foi
registrada no `index.tsx`, o bridge lança `Nenhuma view registrada para a viewId: "..."` e a área
fica vazia - olhe o console do browser.

### 3.2 O tipo do estado: campos opcionais

O tipo TypeScript espelha o `ViewState` do PHP, seguindo as regras do serializador
(`src/Framework/Cube/Remote/ViewStateSerializer.php`):

| No PHP | Chega no React | No tipo TS |
|---|---|---|
| `public int $count = 0` (não anulável) | sempre, inclusive `0` | `count: number` |
| `public ?string $errorMessage = null` | omitido quando `null` | `errorMessage?: string` |
| `public string $averageLabel = ''` | omitido quando em branco | `averageLabel?: string` |
| `public ?CubeView $reviewsPanelView` | `reviewsPanelViewId: "classId:n"` | `reviewsPanelViewId?: string` |
| `public array $reviews` (lista de `ReviewInfo`) | array com os campos públicos de cada item | `reviews?: Review[]` |
| objeto com campos privados (uma entidade) | `{}` | - não faça isso |

Marque como opcional tudo que pode faltar e **dê um padrão ao ler**: `state.reviews ?? []`,
`state.count ?? 0`. Antes da primeira resposta, o estado pode estar vazio. Lembre também que o
servidor já manda os valores prontos para mostrar ("4,5 de 5"): formatação e regras que outra view
escreveria igual pertencem ao presenter.

### 3.3 `this.state` é do servidor

A cada estado novo, o bridge **apaga todas as propriedades** do objeto de estado e copia as que
chegaram (`ViewScope.setState`). O objeto é o mesmo, o conteúdo é trocado. Consequência: qualquer
coisa que a view escreva em `this.state` some na próxima atualização daquela view. Estado local
vai em **campos da classe**, como `rating` e `comment` do painel.

> A `ProductView` existente guarda a quantidade em `state.quantity` e a sincroniza com
> `bridge.setFormField(vsid, "quantity", ...)`. Funciona enquanto o servidor não reenvia o estado
> do produto, mas o valor volta a 1 se isso acontecer - e o campo `"quantity"` (sem `p.`) é
> descartado no servidor, porque o `ProductPresenter` não tem `syncState`. Para código novo,
> prefira campos da classe.

### 3.4 Campos da classe e `forceUpdate()`

```tsx
private rating = 0
private comment = ""

private readonly onRatingChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
  this.rating = Number(e.target.value)
  this.forceUpdate()
}
```

Mudou um campo, chame `this.forceUpdate()`: nada observa os campos, assim como nada observa o
`ViewState` no servidor.

### 3.5 `render(props, initial)`

`render` recebe um segundo parâmetro, `initial`, que é `true` só na primeira renderização da
instância. Serve para preparar algo uma vez - o `PurchasesPanel` registra ali
(`if (initial && !this.resizeHandler) { ... }`) o ouvinte de `resize` que calcula quantas compras
cabem na tela e manda o resultado ao servidor como `p.capacity`.

### 3.6 Emissores: enviando eventos ao servidor

```tsx
readonly emitSubmitReview = () => {
  const { vsid } = this
  bridge.setFormField(vsid, "p.rating", this.rating > 0 ? this.rating : null)
  bridge.setFormField(vsid, "p.comment", this.comment)
  bridge.submit(vsid, ON_SUBMIT_REVIEW)
}
```

- `bridge.setFormField(vsid, nome, valor)` acumula um campo no formulário desta view;
  `bridge.submit(vsid, código)` envia o formulário acumulado junto com o evento e liga a barra de
  "enviando" da `BrowserView` até a resposta. `bridge.submitSilent(...)` envia sem a barra.
- **O código do evento é um contrato.** Cada `submit(vsid, N)` precisa de um braço `N => ...` no
  `match` do skeleton; um código sem braço chega a `Skeleton::unknownEvent`, que lança
  `eventCode(N) not handled` e mostra o alerta de erro inesperado. Mantenha os códigos como
  constantes no topo do arquivo (`// :: Actions`), com os mesmos números do PHP.
- **Argumentos do evento levam o prefixo `p.`.** No PHP chegam em `$form['p.rating']`. Um campo
  **sem** prefixo é sincronização de estado: vai para o terceiro argumento do `Skeleton`
  (`syncState`) e, se o presenter não tem um, é descartado em silêncio.
- **Mande o mínimo**: o id do produto, não o produto. O autor da avaliação nem vai - o servidor
  sabe quem está logado.
- **Dado sensível** é cifrado antes de sair: o login faz
  `bridge.setFormField(vsid, "p.password", await bridge.cipher(password))`.

Os emissores e handlers são **campos `readonly` com arrow function**, não métodos. A arrow
function captura `this`; como campo, a referência é a mesma durante toda a vida da instância
(passar `this.emitX` para `onClick` não cria uma função nova a cada render); `readonly` impede
reatribuição por engano.

### 3.7 O formulário local e o sinal de sucesso

A nota e o comentário do painel vivem **só no React** até o envio. O servidor não manda "deu
certo": ele recarrega a lista, e a contagem muda. A view usa isso como sinal para limpar o
formulário:

```tsx
// a contagem mudou ⇒ o servidor aceitou a avaliação (ou trocou de produto): limpa o formulário
if (count !== this.lastCount) {
  this.lastCount = count
  this.rating = 0
  this.comment = ""
}
```

Se a validação falha, a contagem não muda, o formulário fica como estava e o `errorMessage` do
estado aparece. Se a sua tela precisar de um sinal explícito, ponha um campo no `ViewState` (por
exemplo, um contador de envios aceitos) em vez de inferir.

---

## 4. Organização interna do arquivo

As views do shell seguem a mesma ordem, marcada com comentários `// ::`:

| Seção | Conteúdo |
|---|---|
| `// :: Actions` | constantes dos códigos de evento (`ON_SUBMIT_REVIEW = 1`) |
| `// :: Types` | o tipo do estado e dos itens; padrões (`DefaultProduct`) |
| `// :: View` | a classe: campos locais, `render`, renderizadores auxiliares |
| `// :: Handlers` | reagem a eventos da interface e mudam campos locais (+ `forceUpdate`) |
| `// :: Emissors` | `emitX`: montam o formulário e chamam `bridge.submit` |
| `// :: Internal` | sub-componentes e funções do próprio arquivo (`ReviewItem`, `stars`) |

A distinção entre **handler** e **emissor** ajuda a ler: um handler fica no browser; um emissor
fala com o servidor.

---

## 5. Sub-componentes com `BasePanelClass`

Quando um pedaço da tela tem handlers próprios, extraia uma `BasePanelClass`. Ela não tem
`classId` nem estado do servidor: recebe tudo por `props`, inclusive o `vsid` da view dona quando
precisa emitir eventos em nome dela. O cartão de produto da vitrine:

```tsx
// views/home/ProductsPanel.tsx
type CardProdutoProps = {
  vsid: string
  product: Product
}

class CardProdutoClass extends BasePanelClass<CardProdutoProps> {
  vsid!: string
  product!: Product

  override render({ vsid, product }: CardProdutoProps): React.ReactNode {
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

const CardProduto = BasePanelClass.FC(CardProdutoClass)
```

O `render` copia as props para campos, porque o emissor (criado uma vez) precisa dos valores
**atuais**. O evento sai com o `vsid` da `ProductsPanel` e chega ao skeleton do
`ProductsPanelPresenter` (`1 => $this->onOpenProduct(Coerce::asInt($form['p.productId'] ?? null))`).

---

## 6. Desenhando a view de um filho ou de um slot

Quando o `ViewState` do presenter tem um `?CubeView`, o React recebe o id da view filha e a
desenha com `bridge.createView`:

```tsx
// views/product/ProductView.tsx
export type ProductViewState = {
  // ...
  reviewsPanelViewId?: string // a view do presenter-filho (ReviewsPanelPresenter)
}

// no render, depois do bloco de erro:
{bridge.createView(state.reviewsPanelViewId)}
```

`createView` devolve `null` quando o id não veio, então não precisa de `if`. O mesmo mecanismo
desenha o slot da home - `bridge.createView(state.contentViewId)` mostra o produto, o carrinho ou
o recibo - e os dois painéis (`productsPanelViewId`, `purchasesPanelViewId`). A view dona não
importa o componente do filho: quem escolhe é o bridge, pelo `classId` no id.

---

## 7. CSS e estilos

### 7.1 O par `*-sel.ts` + `.scss`

Cada módulo de view tem um arquivo de **seletores** e uma folha SCSS. A view importa só os
seletores; o `-sel.ts` importa o `.scss`:

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

- **Nunca escreva o nome da classe CSS direto na view.** `className={Sel.header}` dá
  autocompletar e um erro de compilação no editor quando o seletor não existe; `"wdc-reviews__header"`
  digitado à mão quebra em silêncio.
- **Nomes em BEM**, com prefixo `wdc-` e o nome do módulo: `wdc-reviews__item-header`.
- **`...GlobalSel`** traz os utilitários globais (`src/scripts/global-sel.ts`, implementados em
  `src/scss/_utilities.scss`): layout (`flexCol`, `gap16`), espaçamento (`mt16`, `mr4`), texto,
  e blocos prontos como `alertError`/`alertErrorIcon`/`alertErrorText`, que o painel usa para a
  mensagem de erro.
- **Tokens de cor e forma** são variáveis CSS em `src/scss/app.scss`: `--app-accent`,
  `--app-surface`, `--app-text`, `--app-text-secondary`, `--app-border`, `--app-radius`,
  `--app-radius-sm`, as sombras `--app-shadow-*`. Use-as em vez de cores fixas.

### 7.2 Combinando classes com `clsx`

```tsx
<div className={clsx(Sel.panel, className)}>
<i className={clsx("bi bi-star", Sel.mr4)}></i>
```

`clsx` junta classes e ignora valores falsos (`clsx(Sel.tabItem, ativo && Sel.tabItemActive)`).
Repasse o `className` que veio nas props para o elemento raiz: o dono pode querer posicionar a
view.

### 7.3 Componentes visuais

O shell usa os Spectrum Web Components via wrappers React, reexportados em `src/scripts/swc.ts`:
`Theme`, `Button`, `ActionButton`, `Textfield`, `FieldLabel`, `Divider`, `ProgressCircle`.
Importe de `@root/swc`, não do pacote:

```tsx
import { Button } from "@root/swc"

<Button variant="accent" size="m" onClick={this.emitSubmitReview}>
  <i className={clsx("bi bi-star", Sel.mr4)}></i>
  Enviar avaliação
</Button>
```

Algumas views antigas ligam o clique por `ref` (`ref={this.btnRef}` + `addEventListener("click",
...)`), como `HeaderPanel` e `LoginView`; `ProductView` e `ReviewsPanel` usam `onClick` direto.
Elementos HTML comuns (`<select>`, `<textarea>`) também servem, estilizados pelo `.scss` do módulo.

---

## 8. Imagens e assets

- **Ícones**: a fonte Bootstrap Icons é injetada no `index.html` gerado por
  `scripts/inject-externals.js`. Use classes `bi bi-<nome>` (`bi bi-bag-plus`, `bi bi-star`).
- **Imagens de dados** (a foto do produto) vêm do servidor: `EndpointUtils.productImagePath(id)`
  monta `./image/product/<id>.png`, servido pelo `ProductImageHandler` do PHP. Se a sua tela
  precisa de outra imagem por registro, crie a rota no servidor e um helper em `EndpointUtils`.
- **Arte fixa** pequena vira componente SVG inline, como `views/home/ShoppingLogo.tsx`.

---

## 9. Estrutura de arquivos de um módulo de view

```text
frontend/shopping.shell.react/src/scripts/views/product/
  ProductView.tsx       a view do ProductPresenter (classId 48b693f67410)
  product-sel.ts        seletores do produto (importa product.scss)
  product.scss
  ReviewsPanel.tsx      a view do ReviewsPanelPresenter (classId 8cc32236e32a)
  reviews-sel.ts        seletores do painel (importa reviews.scss)
  reviews.scss
```

Um arquivo `.tsx` por view registrada, com `export default` do `FC`. Sub-componentes que só ela
usa ficam no mesmo arquivo (`// :: Internal`); painéis reaproveitados por várias views, num
arquivo próprio (`HeaderPanel.tsx`). O par `-sel.ts`/`.scss` pode ser do módulo inteiro
(`home-sel.ts` serve `HomeView`, `ProductsPanel` e `PurchasesPanel`) ou de uma view só.

---

## 10. Registrando no `index.tsx`

Toda view com `classId` precisa ser registrada, senão o bridge não sabe desenhá-la:

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

`registerComponents` lê o `VIEW_ID` de cada componente (gravado por `BaseViewClass.FC`). Painéis
`BasePanelClass` não são registrados: são componentes comuns, usados por quem os importa.

---

## 11. Compilando

```bash
bin/build-frontend.sh                                   # build completo
(cd frontend/shopping.shell.react && npm run watch)     # desenvolvimento: recompila a cada mudança
```

`bin/build-frontend.sh` chama `frontend/shopping.shell.react/build.sh`, que instala as
dependências na primeira vez (shell e bridge) e roda `npm run build:clean`. O resultado vai para
`work/frontend/shopping.shell.react`, de onde o servidor PHP o serve - não é preciso reiniciar o
servidor, só recarregar o browser. Detalhes do ambiente em [setup-local.md](setup-local.md).

> **O build não verifica tipos.** O Parcel transpila o TypeScript com SWC, que **remove** os tipos
> sem checá-los: um campo com o nome errado no tipo do estado, ou uma prop que não existe, compila
> e só aparece como `undefined` no browser. Confie nos erros que o editor mostra (ele usa o
> `tsconfig.json` do shell) e confira o tipo do estado contra o `ViewState` do PHP.

---

## 12. Exemplo completo - `ReviewsPanel.tsx`

O `render` que faltou na seção 3, e os sub-componentes internos:

```tsx
override render({ className }: ViewProps): ReactNode {
  const { state } = this
  const reviews = state.reviews ?? []
  const count = state.count ?? 0

  // a contagem mudou ⇒ o servidor aceitou a avaliação (ou trocou de produto): limpa o formulário
  if (count !== this.lastCount) {
    this.lastCount = count
    this.rating = 0
    this.comment = ""
  }

  return (
    <div className={clsx(Sel.panel, className)}>
      <div className={Sel.header}>
        <h6 className={Sel.title}>Avaliações</h6>
        {state.averageLabel && <span className={Sel.average}>{state.averageLabel}</span>}
        <span className={Sel.count}>{count === 1 ? "1 avaliação" : `${count} avaliações`}</span>
      </div>

      {reviews.length === 0 ? (
        <div className={Sel.empty}>Este produto ainda não foi avaliado. Seja o primeiro!</div>
      ) : (
        <div className={Sel.list}>
          {reviews.map((review) => (
            <ReviewItem key={review.id} review={review} />
          ))}
        </div>
      )}

      <div className={Sel.form}>
        <div className={Sel.formRow}>
          <label htmlFor="review-rating">Sua nota:</label>
          <select id="review-rating" className={Sel.select} value={this.rating} onChange={this.onRatingChange}>
            <option value={0}>Escolha...</option>
            {[5, 4, 3, 2, 1].map((n) => (
              <option key={n} value={n}>
                {stars(n)} ({n})
              </option>
            ))}
          </select>
        </div>
        <textarea
          className={Sel.textarea}
          placeholder="Conte o que achou do produto (opcional)"
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
            Enviar avaliação
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
        <span className={Sel.author}>{review.author || "Anônimo"}</span>
        <span className={Sel.stars} title={`${review.rating} de 5`}>
          {stars(review.rating)}
        </span>
        <span className={Sel.date}>{DateUtils.formatDate(review.createdAt)}</span>
      </div>
      {review.comment && <p className={Sel.comment}>{review.comment}</p>}
    </div>
  )
}
```

Repare:

- `maxLength` no `<textarea>` é **ajuda**, não regra: o servidor valida de novo (500 caracteres)
  e devolve o erro no estado se alguém passar disso.
- Nota 0 ("Escolha...") vai como `null`; o servidor responde "Escolha uma nota de 1 a 5." no
  `errorMessage`.
- `createdAt` chega em milissegundos e o `DateUtils.formatDate` formata no fuso do browser.
- `review.author || "Anônimo"` e `review.comment &&` cobrem tanto o campo ausente quanto o `""`.

Para o painel aparecer, faltam duas linhas fora deste arquivo: o
`{bridge.createView(state.reviewsPanelViewId)}` na `ProductView` (seção 6) e o `ReviewsPanel` no
`registerComponents` (seção 10).

---

## 13. Checklist ao criar uma view

- [ ] Arquivo `views/<módulo>/XxxView.tsx`, classe `extends BaseViewClass<ViewProps, XxxState>`.
- [ ] `export default BaseViewClass.FC(XxxClass, "<classId>")` com o **mesmo** `classId` do
      `Skeleton` do presenter.
- [ ] Tipo do estado espelhando o `ViewState`: opcional tudo que pode ser `null` ou string em
      branco; `CubeView` como `xxxViewId?: string`; padrão (`??`) em cada leitura.
- [ ] Nada guardado em `this.state`; estado local em campos da classe + `forceUpdate()`.
- [ ] Códigos de evento como constantes em `// :: Actions`, iguais aos do `match` do skeleton -
      um braço para cada código.
- [ ] Argumentos com `bridge.setFormField(vsid, "p.<nome>", valor)` antes do `bridge.submit`; nada
      sem `p.` a menos que o presenter tenha `syncState`.
- [ ] Handlers e emissores como campos `readonly` com arrow function.
- [ ] Views filhas e slots com `bridge.createView(state.xxxViewId)`.
- [ ] Par `xxx-sel.ts` (com `...GlobalSel`) + `xxx.scss` em BEM `wdc-<módulo>__...`; nenhuma
      classe CSS digitada na view; cores pelas variáveis `--app-*`.
- [ ] Componentes visuais de `@root/swc`; ícones `bi bi-*`.
- [ ] Registrada em `bridge.registerComponents(...)` no `index.tsx`.
- [ ] Sem erros de tipo no editor (o build não checa); `bin/build-frontend.sh` ou `npm run watch`;
      teste no browser.
- [ ] O lado do servidor: [presentation-module.md](presentation-module.md). A receita completa:
      [criar-funcionalidade.md](criar-funcionalidade.md).
