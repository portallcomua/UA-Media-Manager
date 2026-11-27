# UA MediaManager — текст сторінки продукту

## Українська

### Керуйте медіатекою WordPress без хаосу

UA MediaManager допомагає впорядкувати зображення та інші медіафайли WordPress: створюйте фізичні папки, переміщуйте й перейменовуйте файли, знаходьте точні дублікати та працюйте з карантином — в одному інтерфейсі.

**Основні можливості**
- Ієрархічні папки всередині uploads, зокрема шаблони `images/blog`, `WooCommerce/images` і `WooCommerce/category`.
- Переміщення вкладень разом із відомими розмірами WordPress.
- Оновлення метаданих і знайдених посилань у вмісті, метаполях та налаштуваннях.
- Перейменування файлів і папок зі збереженням зв’язку з вкладеннями WordPress.
- Карантин із відновленням або остаточним видаленням після ручної перевірки.
- Пошук однакових зображень за SHA-256 без автоматичного видалення.
- ZIP-архів uploads та відновлення файлів із ZIP.
- Пакетна lossless-оптимізація JPEG, PNG і GIF, якщо на сервері встановлено відповідні системні інструменти.
- Інтерфейс українською або англійською залежно від мови WordPress.

**Безпека понад усе**

Перед великими змінами створюйте ZIP-архів медіатеки та окрему резервну копію бази даних. Сканер невикористовуваних файлів показує лише кандидатів — остаточне рішення завжди залишається за адміністратором.

**Сумісність і вимоги**
- WordPress 6.2 або новіший.
- PHP 7.4 або новіший.
- PHP ZipArchive для ZIP-операцій.
- Для lossless-оптимізації потрібні `jpegoptim`, `optipng` та/або `gifsicle`, а також дозвіл PHP на запуск системних команд.

> Поточна гілка 2.0 є тестовою збіркою. Перед комерційним запуском потрібні перевірки на різних конфігураціях WordPress, WooCommerce, Elementor, темах і CDN/offload-сховищах. Ліцензування Freemius та розподіл Free/PRO потрібно налаштувати перед публічним продажем.

## English

### Bring order to your WordPress media library

UA MediaManager helps organize WordPress media: create physical folders, move and rename files, find exact duplicates and manage quarantine from one admin interface.

**Key features**
- Hierarchical folders inside uploads, including `images/blog`, `WooCommerce/images` and `WooCommerce/category` templates.
- Attachment moves that include known WordPress image sizes.
- Updates to attachment metadata and matching references in content, post meta and options.
- File and folder renaming while preserving WordPress attachment records.
- Quarantine with restore or permanent deletion after manual review.
- SHA-256 exact duplicate detection with no automatic deletion.
- ZIP backup of uploads and file restoration from ZIP.
- Batched lossless JPEG, PNG and GIF optimization when the required server tools are installed.
- Ukrainian or English interface based on the WordPress locale.

**Safety first**

Create a ZIP archive of uploads and a separate database backup before bulk changes. Unused-media scans show candidates only; the administrator makes the final decision.

**Requirements**
- WordPress 6.2+
- PHP 7.4+
- PHP ZipArchive for ZIP operations.
- `jpegoptim`, `optipng` and/or `gifsicle`, plus PHP command execution, for lossless optimization.

> The current 2.0 branch is a test build. Compatibility testing across WordPress, WooCommerce, Elementor, themes and CDN/offload storage is still required. Freemius licensing and Free/PRO feature separation must be configured before public commercial release.
