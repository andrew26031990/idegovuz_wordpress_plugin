# Gov ID Login (OneID, MyID) для WordPress

Плагин даёт вход и регистрацию на сайте через государственные сервисы идентификации Узбекистана:

- **[OneID](https://id.egov.uz)** (sso.egov.uz): вход по учётной записи OneID, ЭЦП или Mobile-ID;
- **[MyID](https://myid.uz)** (UZINFOCOM): биометрическая проверка по лицу.

Это два независимых сервиса. Каждый включается и выключается в админке отдельно. Пользователь определяется по
**ПИНФЛ**, поэтому один и тот же человек попадает в один аккаунт, через какой бы сервис он ни вошёл.

> ⚠️ **Интеграция MyID написана по публичной документации ([docs.myid.uz](https://docs.myid.uz/#/ru/websdk))
> без тестового доступа.** Прежде чем включать MyID на боевом сайте, проверьте весь сценарий в тестовой среде
> (`devmyid.uz`) с включённым `WP_DEBUG_LOG`. При возникновении вопросов, пишите автору

## Требования

- WordPress 5.8+, PHP 7.4+
- HTTPS
- Включённые «красивые» постоянные ссылки. Адреса возврата: `/oneid/callback/` и `/myid/callback/`
- Договор с оператором нужного сервиса

## Установка и настройка

Скопируйте папку `wp-content/plugins/govid-login` в `wp-content/plugins/` и активируйте плагин.

Дальше откройте «Настройки → Gov ID Login». На вкладке «Общие» задаются регистрация, роль новых пользователей,
синхронизация профиля и скрытие формы пароля. У каждого сервиса своя вкладка, где настраиваются:

- включение;
- ключи доступа;
- текст и порядок кнопки;
- дополнительные поля.

Ключи доступа надёжнее хранить в `wp-config.php`:

```php
define( 'GOVID_ONEID_CLIENT_ID', '...' );
define( 'GOVID_ONEID_CLIENT_SECRET', '...' );
define( 'GOVID_ONEID_SCOPE', '...' );

define( 'GOVID_MYID_CLIENT_ID', '...' );
define( 'GOVID_MYID_CLIENT_SECRET', '...' );
```

Кнопки сами появляются на `wp-login.php`. Чтобы вывести их в другом месте, используйте шорткод:

```
[govid_login]
[govid_login providers="myid" redirect_to="/account/"]
```

## Какие данные сохраняются

**Всегда:** имя, фамилия, отображаемое имя, отчество (`govid_middle_name`), телефон (`phone`) и ПИНФЛ
(`govid_pin`).

**Дополнительно:** то, что перечислено в поле «Дополнительные поля» на вкладке сервиса. Формат: пути к полям ответа
через запятую, вложенные части через точку. По умолчанию значение сохраняется в мета-поле
`govid_{сервис}_{путь}`. Свой ключ задаётся через двоеточие.

```
# OneID (плоский ответ)
pport_no, birth_date, user_type, legal_info

# MyID (ответ users/me, внутри profile)
common_data.birth_date, common_data.gender, doc_data.pass_data:passport, address.permanent_address
```

Системные ключи (`wp_capabilities` и всё с префиксом таблиц, `session_tokens`, `govid_pin` и т. п.)
перезаписать нельзя.

## OneID

**Подключение.** id.egov.uz → «Разработчикам»: технологическая инструкция и соглашение. В заявке нужно указать:
- домен;
- Redirect URI;
- **внешний IP сервера** (запросы принимаются только с IP из белого списка).

Протокол — один адрес `https://sso.egov.uz/sso/oauth/Authorization.do`:

| Шаг | Запрос |
|-----|--------|
| 1 | Редирект: `response_type=one_code`, `client_id`, `redirect_uri`, `scope`, `state` |
| 2 | POST `grant_type=one_authorization_code` + `code`: в ответ приходит `access_token` |
| 3 | POST `grant_type=one_access_token_identify`: в ответ приходят данные (`ret_cd = "0"` означает успех) |
| 4 | POST `grant_type=one_log_out`: необязательный выход |

## MyID

**Подключение.** Порядок такой: заявка на myid.uz → NDA → тестовый доступ → договор. У MyID нужно
зарегистрировать Redirect URI. Сервис платный.

Используется сценарий WebSDK Redirect Flow:

1. Пользователь вводит на сайте **ПИНФЛ или серию и номер паспорта** и **дату рождения**.
2. Сервер получает токен клиента (`client_credentials`) и создаёт сессию `POST /api/v1/web/sessions`.
   В сессию передаётся реальный IP пользователя.
3. Пользователь перенаправляется на `web.myid.uz` и проходит селфи и проверку «живости».
4. После возврата с `auth_code` пользователь нажимает «Войти» на сайте. Этот шаг подтверждения требует MyID
   для защиты от мошенничества.
5. Сервер обменивает `auth_code` на токен, запрашивает `GET /api/v1/users/me` и берёт ПИНФЛ из
   `profile.common_data.pinfl`.

Если сайт работает за прокси или Cloudflare, выберите в настройке «IP пользователя» нужный заголовок. После 5 неудачных
попыток с одного IP за час MyID временно блокирует запросы.

## Безопасность

- `state` хранится в transient и в HttpOnly-cookie. Это защищает от CSRF и повторного использования ответа. Для MyID
  дополнительно сверяется `session_id`.
- Существующие аккаунты не связываются автоматически ни по e-mail, ни по логину.
- Новым пользователям ставится случайный пароль. Административные роли назначить нельзя.
- Номер документа, который вводят для MyID, на сайте не сохраняется.

## Структура

```
govid-login/
├── govid-login.php                      точка входа
├── uninstall.php
├── assets/                              CSS и JS страницы входа
└── includes/
    ├── class-govid-plugin.php           активация, миграции
    ├── class-govid-settings.php         страница настроек
    ├── class-govid-auth.php             общий поток входа (state, подтверждение, логин)
    ├── class-govid-users.php            поиск и создание пользователей по ПИНФЛ
    ├── class-govid-identity.php         нормализованные данные личности
    ├── class-govid-providers.php        реестр провайдеров
    ├── class-govid-login-button.php     кнопки и шорткод
    ├── class-govid-profile.php          блок в профиле пользователя
    ├── class-govid-http.php             HTTP-обёртка
    └── providers/
        ├── abstract-govid-provider.php  базовый класс провайдера
        ├── class-govid-provider-oneid.php
        └── class-govid-provider-myid.php
```

## Хуки

| Хук | Тип | Назначение |
|-----|-----|-----------|
| `govid_providers` | filter | Добавить свой провайдер (наследник `Govid_Provider`) |
| `govid_allow_login` | filter | Запретить вход: `($ok, WP_User $user, Govid_Identity $identity)` |
| `govid_new_user_data` | filter | Аргументы `wp_insert_user()` для нового пользователя |
| `govid_user_created` | action | После регистрации: `($user_id, $identity)` |
| `govid_profile_synced` | action | После синхронизации: `($user_id, $identity)`, все сырые данные лежат в `$identity->raw` |
| `govid_redirect_uri` | filter | Адрес возврата: `($url, $provider_id)` |
| `govid_oneid_endpoint` | filter | Адрес OneID |
| `govid_myid_api_url`, `govid_myid_web_url` | filter | Адреса MyID |
| `govid_myid_user_ip` | filter | IP пользователя для MyID |

Пример — пускать только пользователей с подтверждённой личностью:

```php
add_filter( 'govid_allow_login', fn( $ok, $user, $identity ) => $ok && $identity->verified, 10, 3 );
```

## Переход с версии 1.x (idegovplugin)

Версия 1.x лежала в папке `idegovplugin`. Для WordPress это отдельный плагин, поэтому порядок такой:

1. Деактивируйте и удалите старый плагин «Id Egov Plugin». При деактивации он сам убирает свою строку из
   `wp-login.php`. Пока старый плагин активен, Gov ID Login показывает в админке предупреждение.
2. Установите и активируйте Gov ID Login.
3. Включите OneID и введите ключи.

При активации Gov ID Login автоматически делает следующее:
- удаляет строку `do_action('id_egov_button_shortcode');` из `wp-login.php`, если она осталась;
- копирует ПИНФЛ из старого мета-поля `pinfl` в `govid_pin`, чтобы пользователи попадали в свои аккаунты;
- заменяет общий пароль `123456789` на случайный.

Кроме этого:
- Адрес возврата OneID изменился на `/oneid/callback/`. **Его нужно зарегистрировать у оператора.**
- `client_secret` версии 1.x лежал прямо в коде и попал в историю git. **Его нужно перевыпустить.**
