<?php
if (!defined('ABSPATH')) { exit; }

final class UAMM_I18n {
    public static function boot() {
        add_filter('gettext', array(__CLASS__, 'translate'), 20, 3);
    }

    public static function is_ukrainian() {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        return strpos(strtolower((string)$locale), 'uk') === 0;
    }

    public static function translate($translation, $text, $domain) {
        if ($domain !== 'ua-mediamanager' || self::is_ukrainian()) return $translation;
        $map = array(
            'Папки медіа' => 'Media folders', 'Папка' => 'Folder',
            'Медіабібліотека' => 'Media library', 'Карантин' => 'Quarantine',
            'Налаштування' => 'Settings', 'Недостатньо прав.' => 'Insufficient permissions.',
            'Вкажіть назву папки.' => 'Enter a folder name.', 'Створити' => 'Create',
            'Усі файли' => 'All files', 'Файлів у медіатеці' => 'Media files',
            'Обсяг папки uploads' => 'Uploads folder size', 'Папок менеджера' => 'Folders',
            'Безпечний режим' => 'Safe mode', 'Увімкнено' => 'Enabled',
            'Пошук файлів…' => 'Search files…', 'Знайти' => 'Search',
            'Сортувати:' => 'Sort by:', 'Назва' => 'Name', 'Тип' => 'Type', 'Дата' => 'Date',
            'Вибрати все на сторінці' => 'Select all on this page', 'До карантину' => 'Move to quarantine',
            'Перемістити до папки…' => 'Move to folder…', 'Перемістити' => 'Move',
            'Деталі' => 'Details', 'Перейменувати' => 'Rename', 'Файлів не знайдено.' => 'No files found.',
            'Карантин медіафайлів' => 'Media quarantine',
            'Файли тут ще не видалені фізично. Їх можна повернути або остаточно видалити.' => 'Files here have not been physically deleted. You can restore or permanently delete them.',
            'Завантажити файли карантину ZIP' => 'Download quarantine ZIP',
            'Відновити' => 'Restore', 'Видалити назавжди' => 'Delete permanently',
            'Карантин порожній.' => 'Quarantine is empty.',
            'Налаштування UA MediaManager' => 'UA MediaManager settings',
            'Автоматична класифікація' => 'Automatic classification',
            'Дозволити пропонувати папки за типом вкладення' => 'Suggest folders by attachment type',
            'Не переміщує файли фізично та не змінює URL.' => 'Does not physically move files or change URLs.',
            'Зберегти' => 'Save', 'Перевірка невикористовуваних файлів' => 'Unused media scan',
            'Знайти можливі невикористовувані' => 'Find possible unused files',
            'Lossless-оптимізація зображень' => 'Lossless image optimization',
            'Запустити lossless-оптимізацію' => 'Start lossless optimization',
            'Пошук дублікатів зображень' => 'Find duplicate images',
            'Знайти дублікати' => 'Find duplicates',
            'Створити стандартні папки' => 'Create standard folders',
            'Коренева папка' => 'Root folder', 'Нова папка' => 'New folder',
            'Важливо про безпеку' => 'Important safety information',
            'Завантажити всю бібліотеку' => 'Download full media library',
            'Папки створюються фізично в uploads. Переміщення файлів оновлює метадані WordPress і знайдені URL у вмісті, метаполях та налаштуваннях.' => 'Folders are created physically in uploads. Moving files updates WordPress metadata and matching URLs in content, custom fields and settings.',
            'Перевірка інструментів сервера…' => 'Checking server tools…',
            'Перед масовими операціями завантажте повний ZIP-архів. Карантин переміщує вкладення до кошика WordPress, звідки його можна відновити. Пошук невикористовуваних файлів у цій версії позначає лише попередніх кандидатів і не видаляє їх автоматично.' => 'Download a full ZIP archive before bulk operations. Quarantine uses the WordPress Trash and can be restored. The unused-file scan only identifies candidates and never deletes them automatically.',
            'Перевірка виконується за SHA-256 вмісту файлів. Перший файл у групі вважається основним, інші показуються як кандидати. Нічого не видаляється автоматично.' => 'Files are compared by SHA-256 content hash. The first file in each group is treated as the primary file; the others are candidates. Nothing is deleted automatically.',
            'Використовуються системні jpegoptim, optipng та gifsicle, якщо вони доступні на сервері. Оптимізація не знижує якість; непідтримувані формати пропускаються. Перед запуском обов’язково завантажте ZIP-резервну копію uploads.' => 'Uses jpegoptim, optipng and gifsicle when available on the server. Optimization does not reduce quality; unsupported formats are skipped. Download a ZIP backup of uploads before starting.',
            'Фізичну папку створено.' => 'Physical folder created.',
            'Файли фізично переміщено до папки; посилання оновлено.' => 'Files moved into the folder and matching references updated.',
            'Файл та його відомі розміри перейменовано. Посилання оновлено.' => 'File and known image sizes renamed; matching references updated.',
            'Папку та її вкладені папки перейменовано. Шляхи вкладень і знайдені URL оновлено.' => 'Folder and its subfolders renamed; attachment paths and matching URLs updated.',
            'Не вдалося відновити файл.' => 'Could not restore the file.',
            'Файл відновлено.' => 'File restored.',
            'Файл остаточно видалено.' => 'File permanently deleted.',
            'На сервері не увімкнено PHP ZipArchive.' => 'PHP ZipArchive is not enabled on the server.',
            'Архів пошкоджений або не є ZIP-файлом.' => 'The archive is damaged or is not a ZIP file.',
            'Відновити з ZIP-архіву' => 'Restore from ZIP archive',
            'Перевірити та відновити ZIP' => 'Validate and restore ZIP',
        );
        return isset($map[$text]) ? $map[$text] : $translation;
    }

    public static function js_strings() {
        $uk = self::is_ukrainian();
        return array(
            'done' => $uk ? 'Готово' : 'Done',
            'enterFolder' => $uk ? 'Вкажіть назву папки.' : 'Enter a folder name.',
            'newFolderName' => $uk ? 'Нова назва папки:' : 'New folder name:',
            'newFileName' => $uk ? 'Нова назва файлу (з розширенням або без):' : 'New file name (with or without extension):',
            'hashScan' => $uk ? 'Перевірка файлів за SHA-256…' : 'Checking files by SHA-256…',
            'noDuplicates' => $uk ? 'Однакових зображень не знайдено.' : 'No identical images found.',
            'primary' => $uk ? 'Основний файл: ' : 'Primary file: ',
            'duplicates' => $uk ? 'Дублікати: ' : 'Duplicates: ',
            'quarantineDuplicates' => $uk ? 'Перемістити дублікати до карантину' : 'Move duplicates to quarantine',
            'confirmDuplicates' => $uk ? 'Перемістити ці дублікати до кошика WordPress? Перевірте, що вони не потрібні окремо.' : 'Move these duplicates to WordPress Trash? Make sure they are not needed separately.',
            'confirmOptimize' => $uk ? 'Перед оптимізацією створіть ZIP-резервну копію uploads. Продовжити?' : 'Create a ZIP backup of uploads before optimization. Continue?',
            'prepare' => $uk ? 'Підготовка…' : 'Preparing…',
            'prepareFiles' => $uk ? 'Підготовка списку файлів…' : 'Preparing file list…',
            'startOptimize' => $uk ? 'Запустити lossless-оптимізацію' : 'Start lossless optimization',
            'connectionError' => $uk ? 'Помилка зв’язку із сервером. Запустіть оптимізацію повторно.' : 'Server connection error. Please restart optimization.',
            'working' => $uk ? 'Обробка…' : 'Working…',
            'scanUnused' => $uk ? 'Знайти можливі невикористовувані' : 'Find possible unused files',
            'scanError' => $uk ? 'Помилка сканування' : 'Scan failed',
            'selectFilesFolder' => $uk ? 'Оберіть файли та папку.' : 'Select files and a folder.',
            'moveError' => $uk ? 'Помилка' : 'Error',
            'optimizerUnavailable' => $uk ? 'Статус недоступний' : 'Status unavailable',
            'startFailed' => $uk ? 'Не вдалося запустити оптимізацію' : 'Could not start optimization',
            'optimizationError' => $uk ? 'Помилка оптимізації' : 'Optimization error',
            'fileCount' => $uk ? 'файлів' : 'files',
            'before' => $uk ? 'Було: ' : 'Before: ',
            'after' => $uk ? 'Стало: ' : 'After: ',
            'saved' => $uk ? 'Заощаджено: ' : 'Saved: ',
            'bytes' => $uk ? 'Б' : 'B',
            'kb' => $uk ? 'КБ' : 'KB',
            'mb' => $uk ? 'МБ' : 'MB',
            'gb' => $uk ? 'ГБ' : 'GB',
        );
    }
}
UAMM_I18n::boot();
