# デプロイガイド

## 目次

- [1. 動作確認](#local-validation)
	- [1.1 ローカル検証](#local-demo)
- [2. 公開環境へのデプロイ](#public-deployment)
	- [2.1 汎用構成（Docker + MySQL）](#provider-neutral-deployment)
	- [2.2 AWS構成（EC2 + DynamoDB）](#aws-deployment)
- [3. 運用](#operations)
	- [3.1 ログインユーザー管理](#create-a-login-user)
	- [3.2 既存環境の更新](#update)
	- [3.3 停止](#stop)
	- [3.4 ストレージ移行](#migration)
		- [3.4.1 MySQLからDynamoDBへの移行](#mysql-to-dynamodb)
		- [3.4.2 DynamoDBからMySQLへの移行](#dynamodb-to-mysql)

<a id="local-validation"></a>

## 1. 動作確認

<a id="local-demo"></a>

### 1.1 ローカル検証

Docker Desktop上でWebコンテナとMySQLコンテナを起動し、アプリの動作を確認します。

#### 必要なもの

- Git
- Docker Desktop

#### 起動する

以下のコマンドはWindows PowerShellで実行します。

```powershell
git clone https://github.com/shota-akita/our-story.git
cd our-story
Copy-Item .env.example .env
docker compose --profile local up -d --build
```

ブラウザで `http://localhost:8080` を開きます。

ローカル専用のデモアカウント:

| 権限 | ユーザー名 | パスワード |
|---|---|---|
| 編集者 | `demo-editor` | `password` |
| 閲覧者 | `demo-viewer` | `password` |

これらはローカル検証専用です。公開環境や本番環境では使用しないでください。

独自のアカウントを作る場合は[3.1 ログインユーザー管理](#create-a-login-user)へ進みます。停止方法は[3.3 停止](#stop)を参照してください。

<a id="public-deployment"></a>

## 2. 公開環境へのデプロイ

<a id="provider-neutral-deployment"></a>

### 2.1 汎用構成（Docker + MySQL）

Docker Engineが動作するLinuxサーバーへWebコンテナを配置し、別途用意したMySQLへ接続します。オンプレミス、各社クラウドVM等で利用できます。

#### MySQLを準備する

標準・サポート対象はMySQL 8.4です。MySQL 8.0および9.4でも互換性を確認しています。

MySQLはインターネットへ直接公開せず、Webコンテナから接続できるプライベートネットワーク内に配置します。アプリからの接続には、必要なデータベースだけを操作できる専用ユーザーを使用してください。

既存のMySQLへ適用する場合は、先にバックアップを取得します。リポジトリのルートディレクトリで、Bashから[db/schema.sql](../db/schema.sql)を一度だけ適用します。`YOUR_MYSQL_HOST`と`YOUR_MYSQL_ADMIN_USER`は接続先の値へ置き換えてください。`-p`の後でMySQL管理ユーザーのパスワードを入力します。

```bash
mysql -h YOUR_MYSQL_HOST -u YOUR_MYSQL_ADMIN_USER -p < db/schema.sql
```

末尾の`< db/schema.sql`は、SQLファイルをMySQLクライアントへ渡すBashの入力リダイレクトです。

#### デプロイする

```bash
git clone https://github.com/shota-akita/our-story.git
cd our-story
cp .env.example .env
```

`.env`にはMySQLの接続情報を記入します。Gitへコミットしないでください。

`.env`を接続先に合わせて設定します。

```dotenv
DB_DRIVER=mysql
DB_HOST=YOUR_MYSQL_HOST
DB_USER=YOUR_MYSQL_USER
DB_PASSWORD=YOUR_MYSQL_PASSWORD
WEB_PORT=8080
```

`local`プロファイルは付けず、Webコンテナだけを起動します。

```bash
docker compose up -d --build
docker compose ps
```

公開時はリバースプロキシまたはロードバランサーでHTTPSを有効にしてください。

次に[3.1 ログインユーザー管理](#create-a-login-user)のMySQL手順で、公開環境用のアカウントを登録します。停止方法は[3.3 停止](#stop)を参照してください。

<a id="aws-deployment"></a>

### 2.2 AWS構成（EC2 + DynamoDB）

Amazon EC2でWebコンテナを実行し、保存先にAmazon DynamoDBを使用します。

#### DynamoDBを準備する

DynamoDBテーブルとIAMロールを作成できるAWS権限で、次のテーブルを作成します。

| テーブル | パーティションキー |
|---|---|
| `our-story-memories` | `id`（String） |
| `our-story-users` | `username`（String） |

長期アクセスキーをファイルへ保存せず、DynamoDB操作用IAMロールをEC2へ付与してください。IAMロールには、対象の2テーブルに必要な操作だけを許可します。

#### EC2へデプロイする

Docker EngineとDocker Composeを導入したEC2インスタンスへSSH接続し、次を実行します。

```bash
git clone https://github.com/shota-akita/our-story.git
cd our-story
cp .env.example .env
```

`.env`はGitへコミットしないでください。

`.env`を設定します。IAMロールを使うため、静的なAWS認証情報は空欄のままにします。

```dotenv
DB_DRIVER=dynamodb
AWS_REGION=ap-northeast-1
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_SESSION_TOKEN=
DYNAMODB_MEMORIES_TABLE=our-story-memories
DYNAMODB_USERS_TABLE=our-story-users
APP_PLATFORM=ec2
WEB_PORT=8080
```

起動します。

```bash
docker compose up -d --build
docker compose ps
```

Security Groupで公開ポートを必要最小限に制限し、公開時はHTTPSを有効にしてください。

次に[3.1 ログインユーザー管理](#create-a-login-user)のDynamoDB手順で、公開環境用のアカウントを登録します。停止方法は[3.3 停止](#stop)を参照してください。

<a id="operations"></a>

## 3. 運用

<a id="create-a-login-user"></a>

### 3.1 ログインユーザー管理

この章はMySQLとDynamoDBの共通手順です。先に利用する保存先のデプロイを完了してください。

ユーザー名は任意に決められます。ログイン用パスワードは平文では保存せず、PHPの`password_hash()`で生成したハッシュを保存します。

公開環境ではローカル用デモアカウントを使用せず、環境ごとに固有のユーザー名と十分に強いパスワードを設定してください。

> `.env`の`DB_PASSWORD`はMySQLへ接続するためのパスワードです。Web画面へログインするユーザーのパスワードとは別物です。

アカウントには次の3項目が必要です。

| 項目 | 設定値 |
|---|---|
| `username` | 任意のユーザー名 |
| `password` | `password_hash()`で生成したハッシュ |
| `redirect_target` | 編集者は`index.php`、閲覧者は`index2.php` |

#### 3.1.1 パスワードハッシュを生成する

PowerShellで次を実行します。

```powershell
docker run --rm -i php:8.4-cli php -r '$p = trim(fgets(STDIN)); echo password_hash($p, PASSWORD_DEFAULT), PHP_EOL;'
```

入力待ちになったら、設定したいログイン用パスワードを入力してEnterを押します。出力された`$2y$...`から始まる文字列を次の登録手順で使用します。

#### 3.1.2 MySQLへ登録する

MySQLクライアントを開きます。パスワードを求められたら、`.env`の`DB_PASSWORD`を入力します。

ローカルMySQLの場合:

```powershell
docker compose exec db mysql -u root -p auth_system
```

外部MySQLの場合:

```bash
mysql -h YOUR_MYSQL_HOST -u YOUR_MYSQL_USER -p auth_system
```

MySQLプロンプトで次を実行します。`<username>`と`<password_hash>`を実際の値へ置き換えてください。

```sql
INSERT INTO login_users (username, password, redirect_target)
VALUES ('<username>', '<password_hash>', 'index.php')
ON DUPLICATE KEY UPDATE
		password = '<password_hash>',
		redirect_target = 'index.php';
```

閲覧専用ユーザーにする場合は、`index.php`を`index2.php`へ変更します。個人用アカウントは[db/seed.sql](../db/seed.sql)へ追加せず、実行中のDBへ登録してください。seedファイルはローカル検証用デモアカウント専用です。

#### 3.1.3 DynamoDBへ登録する

`our-story-users`テーブルへ次のString属性を持つ項目を追加します。

```json
{
	"username": "<username>",
	"password": "<password_hash>",
	"redirect_target": "index.php"
}
```

閲覧専用ユーザーにする場合は、`redirect_target`を`index2.php`にします。

<a id="update"></a>

### 3.2 既存環境の更新

デプロイ先で対象ブランチを早送り更新します。

MySQL構成では更新前にバックアップを取得し、運用中も定期的にバックアップしてください。

```bash
cd our-story
git fetch origin
git checkout main
git pull --ff-only
```

`--ff-only`は、デプロイ先で意図しないマージコミットが作られることを防ぎます。

ローカルMySQL構成の場合:

```powershell
docker compose --profile local up -d --build
```

外部MySQLまたはDynamoDB構成の場合:

```bash
docker compose up -d --build
```

<a id="stop"></a>

### 3.3 停止

#### 3.3.1 ローカル環境

次のどちらか一方を、実行前に選びます。通常は「A. データを残して停止」を使用してください。

##### A. データを残して停止（推奨）

コンテナだけを停止・削除します。登録した思い出やログイン情報はDockerボリュームに残るため、次回起動時も利用できます。

```powershell
docker compose --profile local down
```

この操作ではデータは削除されません。通常の停止作業はここで完了です。以下の「B」は実行しないでください。

##### B. ローカルデータを削除して完全リセット

> **注意:** 次のコマンドはMySQLのDockerボリュームも削除します。登録した思い出やログイン情報は元に戻せません。データベースを初期状態から作り直す場合にだけ実行してください。

```powershell
docker compose --profile local down -v
```

#### 3.3.2 公開環境

汎用構成とAWS構成では、Webコンテナを次のコマンドで停止します。

```bash
docker compose down
```

外部MySQLやDynamoDBのデータは削除されません。サーバー、MySQL、DynamoDBテーブル自体を削除する場合は、対象と影響範囲を確認してから個別に操作してください。

<a id="migration"></a>

### 3.4 ストレージ移行

`DB_DRIVER`は接続先だけを切り替え、既存データを自動では移行しません。運用中のデータを別の保存先へ移す場合は、書き込みを停止してから該当する移行スクリプトを実行します。

<a id="mysql-to-dynamodb"></a>

#### 3.4.1 MySQLからDynamoDBへの移行

非AWS構成のMySQLデータを、AWS構成のDynamoDBへ移します。

##### 移行前の確認

1. 読み取り元MySQLをバックアップする
2. 移行先に`our-story-memories`と`our-story-users`テーブルを作成する
3. `.env`に読み取り元MySQLと書き込み先DynamoDBの両方を設定する
4. IAMロールまたはAWS認証情報に、対象テーブルの操作権限を付与する
5. 移行中はアプリからMySQLへの書き込みを停止する

ローカル用の`demo-editor`と`demo-viewer`は既定で移行対象から除外されます。

##### 事前検証（dry run）

外部MySQLを使用している場合:

```bash
docker compose run --rm web php scripts/migrate_mysql_to_dynamodb.php --dry-run
```

Docker ComposeのローカルMySQLを使用している場合:

```powershell
docker compose --profile local run --rm web php scripts/migrate_mysql_to_dynamodb.php --dry-run
```

事前検証ではMySQLのデータとDynamoDBのテーブル定義を確認しますが、DynamoDBの項目は変更しません。表示された件数を移行元と照合してください。

##### 移行する

事前検証のコマンドから`--dry-run`を外して実行します。

```bash
docker compose run --rm web php scripts/migrate_mysql_to_dynamodb.php
```

スクリプトは次のデータを移行します。

- `memories`（IDはDynamoDBのStringキーへ変換）
- `locations`（各memoryの配列へ統合）
- `login_users`（パスワードハッシュと権限を維持）

再実行時は同じキーの項目を更新します。DynamoDBにだけ存在する項目も削除して完全同期する場合は、事前にバックアップを取得し、`--dry-run --prune`で削除件数を確認してから`--prune`を付けて実行してください。

ローカル検証でデモアカウントも移す場合に限り、`--include-demo-users`を追加します。公開環境では使用しないでください。

移行後は件数と主要データを確認してから、アプリの`DB_DRIVER`を`dynamodb`へ切り替えてください。

<a id="dynamodb-to-mysql"></a>

#### 3.4.2 DynamoDBからMySQLへの移行

DynamoDBで運用しているデータをMySQLへ移します。

##### 移行前の確認

1. 移行先MySQLをバックアップする
2. 移行先MySQLへ[db/schema.sql](../db/schema.sql)を適用する
3. 既存MySQLを再利用する場合は、`memories.id`と`locations.memory_id`が`BIGINT`であることを確認する
4. `.env`に読み取り元DynamoDBと書き込み先MySQLの両方を設定する
5. 移行中はアプリからDynamoDBへの書き込みを停止する
6. 本番実行前に検証用MySQLで試行する

`INT`のままの既存スキーマには、現在のDynamoDBが生成する16桁IDを保存できません。その場合は、現行の[db/schema.sql](../db/schema.sql)から作成した新しいMySQLを移行先に使用してください。

##### 移行する

```bash
docker compose run --rm web php scripts/migrate_dynamodb_to_mysql.php
```

スクリプトは次のデータを移行します。

- `memories`
- `locations`
- `login_users`

ローカル用の`demo-editor`と`demo-viewer`は既定で移行対象から除外されます。ローカル検証で移す場合に限り、`--include-demo-users`を追加します。

DynamoDB内のlocation IDは画面表示用の内部値です。MySQLへ移行すると、`locations`テーブルのAUTO_INCREMENTで新しいIDが割り当てられます。場所名とmemoryとの関連は維持されます。

MySQLにだけ存在する行も削除して完全同期する場合は、事前にバックアップを取得してから`--prune`を付けて実行してください。

移行後は件数と主要データを確認してから、アプリの`DB_DRIVER`を`mysql`へ切り替えてください。
