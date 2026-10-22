# Escalando o Cube MVP em PHP

Como passar de um servidor para vários quando um processo PHP não dá mais conta. Para quem vai
implantar ou operar uma aplicação Cube em PHP (o Shopping é o exemplo).

**Resumo:** rode várias instâncias do servidor atrás de um balanceador com suporte a WebSocket,
todas com o mesmo PostgreSQL e as mesmas chaves. O balanceador manda cada sessão sempre para a
mesma instância, roteando pelo `appId` da URL do WebSocket.

## 1. Como uma instância atende os usuários

Cada instância é **um processo PHP com um event loop** (Amp). Cada usuário conectado é uma Fiber
dentro desse processo, e todos dividem **uma conexão com o banco**.

- O código da aplicação (presenters, repositórios) é síncrono. Enquanto uma consulta roda, o
  processo inteiro espera e nenhuma outra Fiber anda. As consultas de usuários diferentes entram em
  fila e rodam uma de cada vez.
- As esperas de rede (WebSocket, HTTP, timers) não bloqueiam: aí o loop atende os outros.
- O que gasta muita CPU de uma vez (o PBKDF2 do handshake, ~180 ms) já roda fora do loop, em
  processos-worker (`amphp/parallel`).

Isso basta para muitos usuários: uma consulta típica leva milissegundos, porque o `JsonQuery`
monta o grafo num SELECT só. Mas o modelo tem três limites:

| Limite | Efeito |
|---|---|
| Um núcleo de CPU por processo | a instância não usa o resto da máquina |
| Uma consulta por vez | o banco poderia atender várias em paralelo e não atende |
| Consulta lenta trava todos | uma consulta de 2 s congela por 2 s todos os usuários da instância |

## 2. Quando escalar

Sinais de que uma instância não basta:

- o processo passa muito tempo perto de 100% de um núcleo;
- o tempo de resposta cresce com o número de sessões, mesmo com consultas rápidas;
- o indicador "enviando" do shell aparece com frequência.

Antes de multiplicar instâncias, confira se não é uma consulta lenta (índice faltando, projeção
grande demais): ela trava todos e piora com mais usuários, com uma ou com dez instâncias.

## 3. A estratégia: várias instâncias + balanceador

```
                    ┌──────────────┐
  browsers ──WS──▶  │ balanceador  │──▶ instância A ─┐
  apps ─────HTTP──▶ │ (afinidade)  │──▶ instância B ─┼──▶ PostgreSQL
                    └──────────────┘──▶ instância C ─┘
```

Cada instância é independente: tem seu event loop, sua conexão com o banco e as sessões dos seus
usuários. O ganho vem de duas coisas: cada instância usa um núcleo, e o PostgreSQL atende as
consultas das várias instâncias ao mesmo tempo.

## 4. Onde vive cada estado

É isso que decide o que precisa de afinidade e o que precisa ser igual em todas as instâncias.

| Estado | Onde vive | Consequência |
|---|---|---|
| Sessão Cube (presenters, carrinho, tela atual) | memória da instância | precisa de afinidade |
| Dados (usuários, produtos, compras) | banco | compartilhado: tem de ser PostgreSQL |
| Chaves `[remote]` (assinatura do `appId`, `app_skey`) | configuração | iguais em todas as instâncias |
| Token "lembrar-me" (login automático) | storage do cliente, JWT | vale em qualquer instância (mesmo `jwt.secret`) |
| API REST: nonce do desafio, sessão do access token, refresh | memória da instância | precisa de afinidade |
| Transação remota (`X-Tx-Id`) | conexão aberta na instância do `begin` | precisa de afinidade |
| Cache da CDN (`/web-cache`) | disco | pode ser por instância |

## 5. Requisitos

- [ ] **PostgreSQL.** O SQLite admite um só escritor por vez, e o servidor está configurado para
  falhar na hora em vez de esperar. Com vários processos no mesmo arquivo, escritas simultâneas
  dariam erro.
- [ ] **As mesmas chaves `[remote]` em todas as instâncias.** Uma instância só aceita `appId`
  assinados com a sua chave. Sem a seção, cada instância geraria as suas próprias chaves de
  desenvolvimento e não reconheceria as sessões das outras. Gere as chaves uma vez e distribua o
  mesmo `application.local.toml`.
- [ ] **O mesmo `security.jwt.secret`** em todas.
- [ ] **`server.devMode = false`**: desliga o `POST /__dev/db-reset`.
- [ ] **Afinidade no balanceador** (seção 6).
- [ ] **Health check** em `GET /health` (responde `{"status":"UP"}`).

Exemplo de `application.local.toml` comum a todas as instâncias:

```toml
[database]
url = "pgsql:host=db.interno;port=5432;dbname=shopping"
username = "shopping"
password = "..."

[security]
jwt.secret = "..."

[server]
devMode = false

[remote]
rsaPublicKey = "..."
rsaPrivateKey = "..."
signPublicKey = "..."
signPrivateKey = "..."
```

## 6. Afinidade

### WebSocket: pelo `appId`

O shell abre o WebSocket em `/<frontend>/dispatcher/<appId>` (ou `/dispatcher/<appId>`). Basta
rotear por esse `appId`, com hash consistente, e a sessão volta sempre à mesma instância, inclusive
nas reconexões.

O `index.html` **não** precisa de afinidade. Qualquer instância pode gerar o `appId`, porque todas
assinam com a mesma chave. A sessão só nasce na primeira mensagem do WebSocket, na instância para
onde o hash apontar.

### API REST: pelo `X-Client-Id`

Os clientes REST mandam `X-Client-Id` em toda requisição, um id por instância do
app. Roteie por ele: desafio, login, refresh e transação remota caem na mesma instância.

### Quando a afinidade falha

Isso acontece quando uma instância cai, no reinício de um deploy ou quando o número de instâncias
muda e o hash redistribui as sessões.

- **WebSocket:** a outra instância não recusa. Ela cria uma sessão nova a partir do que o cliente
  guarda: volta à mesma tela (o `path` da URL) e refaz o login pelo token "lembrar-me". O que estava
  só na memória da instância antiga se perde: o carrinho, por exemplo.
- **API REST:** o access token não é reconhecido (401), e o refresh também não, porque a sessão
  estava na memória da outra instância. O cliente precisa refazer o login. Uma transação remota
  aberta na instância antiga é perdida e revertida pela varredura de lá.

## 7. Exemplo com nginx

Quatro instâncias na mesma máquina, nas portas 8081 a 8084:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

upstream wdc_ws {                       # sessões Cube: afinidade pelo appId
    hash $wdc_app consistent;
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    server 127.0.0.1:8083;
    server 127.0.0.1:8084;
}

upstream wdc_api {                      # API REST: afinidade pelo cliente
    hash $http_x_client_id consistent;
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    server 127.0.0.1:8083;
    server 127.0.0.1:8084;
}

upstream wdc_any {                      # páginas, imagens, health: qualquer uma
    server 127.0.0.1:8081;
    server 127.0.0.1:8082;
    server 127.0.0.1:8083;
    server 127.0.0.1:8084;
}

server {
    listen 80;

    location ~ ^/(?:[^/]+/)?dispatcher/(?<wdc_app>[^/]+)$ {
        proxy_pass http://wdc_ws;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;
        proxy_read_timeout 120s;        # o servidor manda ping a cada 10 s
    }

    location ~ ^/(?:[^/]+/)?api/(?:auth|repo|tx)/ {
        proxy_pass http://wdc_api;
        proxy_set_header Host $host;
        client_max_body_size 16m;       # upload de imagem de produto
    }

    location / {
        proxy_pass http://wdc_any;
        proxy_set_header Host $host;
    }
}
```

## 8. Subindo as instâncias

Cada instância é um `php bin/server.php <porta>` com a mesma configuração. Com systemd, uma
unidade-modelo resolve:

```ini
# /etc/systemd/system/wdc-shopping@.service
[Unit]
Description=WDC Shopping (PHP) na porta %i
After=network.target

[Service]
WorkingDirectory=/opt/wdc-cube-php
Environment=SHOPPING_CONFIG_FILE=/etc/wdc/application.toml
Environment=LOG_LEVEL=info
ExecStart=/usr/bin/php bin/server.php %i
Restart=always
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now wdc-shopping@8081 wdc-shopping@8082 wdc-shopping@8083 wdc-shopping@8084
```

O `SIGTERM` encerra a instância de forma ordenada (`ShoppingServer::stop()`).

Em produção, desligue as asserções do PHP (`zend.assertions = -1` no `php.ini`). O Amp avisa no log
quando estão ligadas.

## 9. Dimensionamento

- **Instâncias por máquina:** uma por núcleo é o ponto de partida. Deixe folga para os workers do
  PBKDF2, que também usam CPU nos picos de login.
- **Conexões no PostgreSQL:** cada instância usa 1 conexão, mais 1 por transação remota aberta
  (teto de 128 por instância). Some tudo e confira o `max_connections` do banco.
- **Sessões por instância:** `server.maxSessions` limita (o padrão `-1` é sem limite). Acima do
  limite, a conexão é fechada com o código 4003. Uma sessão sem conexão expira em
  `server.sessionTtlSeconds` (padrão 180 s).
- **Conexões por IP:** o servidor aceita até 1000 conexões simultâneas por IP. Atrás de um
  balanceador, todas chegam do IP dele, então esse é o teto prático de conexões por instância. O
  valor está em `ShoppingServer` (`connectionLimitPerIp`); suba-o se precisar de mais.
- **Memória:** cresce com o número de sessões (presenters e estado das telas). Meça com o seu uso
  real antes de fixar o número de sessões por instância.

## 10. Deploy sem derrubar todo mundo

Atualize **uma instância por vez**. Os usuários dela reconectam, o hash os manda para outra
instância e eles ganham uma sessão nova: mesma tela, login automático, carrinho perdido (seção 6).
Com o hash consistente, só as sessões da instância reiniciada mudam de lugar.

Para não perder nem o carrinho, seria preciso drenar a instância antes de pará-la (parar de mandar
sessões novas para ela e esperar as atuais acabarem). O balanceador do exemplo não faz isso sozinho.

## 11. Limitações e próximos passos

| O que | Como resolver |
|---|---|
| Login REST preso à instância (nonce e sessões em memória) | guardar nonce e sessões de refresh no banco: aí a API REST dispensa afinidade, exceto para transações remotas |
| Uma consulta por vez em cada instância | driver assíncrono (`amphp/postgres`, com pool): enquanto uma consulta espera o banco, as outras Fibers seguem. Exige trocar o PDO por ele na camada de persistência. Não existe para SQLite |
| Carrinho perdido quando a sessão muda de instância | guardar o carrinho no storage do cliente ou no banco |
| Capacidade real desconhecida | benchmark: N sessões simultâneas e o tempo de resposta, para achar quantos usuários uma instância aguenta |
