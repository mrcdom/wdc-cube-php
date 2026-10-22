# WDC Cube PHP

**Cube MVP** em PHP: uma arquitetura em que os *presenters* — a lógica de cada tela — rodam num
servidor PHP de longa duração, e o cliente só desenha o estado que recebe. O cliente incluído é um
shell React que conversa com o servidor por WebSocket.

O repositório traz o framework e uma aplicação de demonstração completa, o **Shopping**.

- [docs/arquitetura.md](docs/arquitetura.md) — como o Cube funciona: places, presenters, navegação,
  protocolo, persistência sem N+1, segurança.
- [docs/escalando.md](docs/escalando.md) — várias instâncias atrás de um balanceador.

## O Shopping

Uma loja simples, de ponta a ponta:

- **login** por desafio HMAC (a senha nunca trafega), com "lembrar-me";
- **catálogo** de produtos com imagem e descrição;
- **carrinho**: adicionar, mudar quantidade, remover, comprar;
- **recibo** da compra e **histórico** das compras do usuário;
- **API REST** com JWT, permissões por papel e transações remotas.

Usuários de demonstração (senha = login): `admin` (administrador, vê os dados de todos), `fulano` e
`beotrano` (clientes).

## Requisitos

- PHP 8.4+ com as extensões `gmp`, `openssl` e `pgsql`
- PostgreSQL 14+
- Composer 2
- Node.js 20+ (só para compilar o shell React)

No macOS: `brew install php composer node postgresql@17` (o PHP do Homebrew já traz `pgsql`).

## Compilar e rodar

Antes da primeira subida, ponha o usuário e a senha do PostgreSQL em
`work/config/application.local.toml` (fora do git):

```toml
[database]
username = "postgres"
password = "..."
```

```bash
composer install          # dependências PHP
bin/build-frontend.sh     # compila o shell React para work/frontend/
php bin/server.php        # sobe o servidor em http://localhost:8080 (Ctrl+C encerra)
```

Abra http://localhost:8080/ (redireciona para o shell React) e entre com `admin`/`admin`.

Para desenvolver o frontend com recompilação automática:
`(cd frontend/shopping.shell.react && npm run watch)`.

## Testes

Os testes usam um banco PostgreSQL só para eles — **é apagado e recriado a cada execução**, então
nunca use o mesmo do servidor. Configure-o no `application.local.toml`:

```toml
[test.database]
url = "pgsql:host=localhost;port=5432;dbname=wedocode_shopping_php_test"
username = "postgres"
password = "..."
```

```bash
composer check            # PHPStan (nível 8) + PHPUnit
```

Em CI, as variáveis `WDC_TEST_DB_URL`, `WDC_TEST_DB_USER` e `WDC_TEST_DB_PASSWORD` substituem o arquivo.

Os testes cobrem o motor, a persistência, os presenters (testes de aceitação com views falsas), o
protocolo remoto, a API REST e o servidor de ponta a ponta (HTTP e WebSocket numa porta livre).
O teste do servidor que usa o shell React é pulado se o frontend não tiver sido compilado.

## Configuração

Arquivo principal: [work/config/application.toml](work/config/application.toml). Sobrescritas
locais, fora do git: `work/config/application.local.toml`, na mesma pasta.

Na primeira subida, o servidor cria o banco (se o usuário tiver permissão), o schema e os dados de
demonstração, e gera chaves de desenvolvimento em `application.local.toml`. **Troque-as em
produção**, assim como o `security.jwt.secret`.

Variáveis de ambiente: `SERVER_PORT`, `LOG_LEVEL` (`debug`, `info`, ...) e `SHOPPING_CONFIG_FILE`
(outro arquivo de configuração).

## Banco de dados

PostgreSQL, acessado de forma assíncrona (`amphp/postgres`): uma consulta suspende só a sessão que
a fez, e várias consultas rodam ao mesmo tempo, cada uma numa conexão do pool. Em `[database]`:

- `url` — endereço, padrão `pgsql:host=localhost;port=5432;dbname=wedocode_shopping_php`;
- `username`, `password` — no `application.local.toml`;
- `pool.maxConnections` — tamanho do pool (padrão 16).

Se o banco não existir, o servidor tenta criá-lo; o schema e os dados de demonstração são criados
na primeira subida.

## API REST

Na raiz e sob cada frontend (`/<frontend>/api/...`):

- `GET /api/auth/challenge`, `POST /api/auth/{login,refresh,logout}` — login por desafio HMAC, JWT
- `POST /api/repo/<user|product|purchase|purchase-item>/<insert|update|delete|count|fetch|fetch-page|fetch-by-id>`,
  `GET /api/repo/<entidade>/{id}`, `GET|PUT /api/repo/product/{id}/image`
- `POST /api/tx/{begin,commit,rollback}`, `GET /api/tx/status` — transação remota (cabeçalho `X-Tx-Id`)

Com `security.jwt.secret` definido, `/api/repo` e `/api/tx` exigem `Authorization: Bearer <jwt>`;
sem ele, a segurança fica desligada. Os limites da transação remota ficam em
`[database] remoteTransaction.*`.

Exemplo — contar os produtos com preço a partir de 20, depois de obter um token:

```bash
curl -X POST localhost:8080/api/repo/product/count \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"price": {"p": [{"o": "GE", "v": [20.0]}]}}'
```

## Estrutura

- `src/Framework/` — o framework Cube: motor, protocolo remoto, persistência, API REST
- `src/Shopping/` — a aplicação de demonstração: domínio, persistência, apresentação, servidor
- `frontend/shopping.shell.react/` — o shell React (React, TypeScript, MUI)
- `frontend/remote.bridge.react/` — o bridge: o protocolo do Cube no lado do cliente
- `tests/` — PHPUnit
- `bin/` — servidor e build do frontend
- `skills/cube-architecture-php/` — skill para assistentes de código (Claude Code): como escrever, revisar e depurar código nesta arquitetura
- `work/` — configuração e dados de runtime

## Licença

[MIT](LICENSE).
