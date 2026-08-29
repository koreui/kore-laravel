<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Overlay Defaults
    |--------------------------------------------------------------------------
    |
    | Default configuration for overlay components (modal, drawer, confirm,
    | bottom-sheet, fullscreen). Each overlay component can override these
    | values via static methods.
    |
    */
    'overlay' => [
        'defaults' => [
            'type' => 'modal',
            'size' => '2xl',
            'position' => 'center',
            'close_on_click_away' => true,
            'close_on_escape' => true,
            'escape_closes_all' => true,
            'dispatch_close_event' => false,
            'destroy_on_close' => false,
            'backdrop_blur' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feedback Defaults
    |--------------------------------------------------------------------------
    |
    | Configuration for toast notifications and confirm dialogs.
    |
    */
    'feedback' => [
        'toast' => [
            'position' => 'top-right',
            'timeout' => 5,
            'dismissible' => true,
            'max_visible' => 5,
            'spacing' => 'gap-3',
            'z_index' => 'z-[60]',
            'expand_delay' => 150,
            'collapse_delay' => 300,
            'swipe_to_dismiss' => true,
        ],
        'confirm' => [
            'size' => 'md',
            'confirm_text' => 'Confirmar',
            'cancel_text' => 'Cancelar',
            'closes_on_escape' => true,
            'closes_on_click_away' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Form Defaults
    |--------------------------------------------------------------------------
    |
    | Default configuration for form components.
    |
    */
    'form' => [
        'size' => 'md',
        'show_errors' => true,
        'select' => [
            'debounce' => 300,
            'min_search' => 2,
            'search_threshold' => 10,
        ],
        'password' => [
            'toggleable' => true,
            'strength' => false,
            'min_length' => 8,
        ],
        // El editor de texto enriquecido.
        'editor' => [
            'placeholder' => null,
            'min_height' => '12rem',
            'max_height' => null,
            // Cuánto se espera desde la última tecla antes de mandar el HTML al
            // servidor. Escribir dispara un evento por pulsación; sin esto sería
            // una petición por letra.
            'debounce' => 400,
            // Los botones y su orden; `|` es un separador. Quitar uno de aquí lo
            // quita de la barra, pero NO impide el formato: los atajos del
            // teclado siguen ahí y el HTML pegado también. Para acotar de verdad
            // lo que se admite, la lista blanca está en el sanitizador.
            'toolbar' => [
                'bold', 'italic', 'underline', 'strike', '|',
                'h2', 'h3', '|',
                'ul', 'ol', 'quote', 'pre', '|',
                'left', 'center', 'right', '|',
                'link', 'unlink', 'image', '|',
                'clear', 'undo', 'redo', 'fullscreen',
            ],
            // Subida de imágenes. El botón solo aparece si la etiqueta declara
            // `upload-property` y `upload-method`: dónde se guarda un archivo y
            // con qué URL se sirve es decisión de la aplicación, no de aquí.
            'upload' => [
                'mimes' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
                'max_size' => 2048,   // KB
            ],
        ],

        'number' => [
            'currency' => 'USD',
            'locale' => null,
            'precision' => 2,
        ],
        'textarea' => ['rows' => 4],
        'datepicker' => [
            'locale' => null,
            'start_of_week' => 1,
            'format' => null,
        ],
        'upload' => [
            'max_size' => null,
            'delete_method' => 'deleteUpload',
            'auto_upload' => true,
            'retryable' => false,
            'max_retries' => 3,
            'retry_delay' => 2000,
        ],
        'rating' => [
            'stars' => 5,
            'clearable' => true,
        ],
        'color_picker' => [
            'columns' => 8,
            'allow_custom' => true,
        ],
        'maskable' => [
            'slot_char' => '_',
            'auto_clear' => false,
            'emit_formatted' => false,
        ],
        'key_value' => [
            'addable' => true,
            'deletable' => true,
            'reorderable' => false,
            'max' => null,
        ],
        'repeater' => [
            'min' => 0,
            'max' => null,
            'reorderable' => false,
        ],

        /*
        | Nombres accesibles de los controles que solo llevan un icono.
        |
        | Van aquí y no incrustados en el Blade porque son el ÚNICO nombre que
        | recibe quien navega con lector de pantalla: un botón con una «×»
        | dentro no dice nada por sí mismo, y `title` no se expone de forma
        | fiable en táctil. Al ser texto de interfaz, tienen que poder
        | traducirse sin publicar la vista.
        */
        'translations' => [
            // El editor de texto enriquecido: nombres de los botones de la
            // barra y del diálogo del enlace.
            'editor_toolbar' => 'Herramientas de formato',
            'editor_bold' => 'Negrita',
            'editor_italic' => 'Cursiva',
            'editor_underline' => 'Subrayado',
            'editor_strike' => 'Tachado',
            'editor_h2' => 'Título',
            'editor_h3' => 'Subtítulo',
            'editor_ul' => 'Lista con viñetas',
            'editor_ol' => 'Lista numerada',
            'editor_quote' => 'Cita',
            'editor_link' => 'Enlace',
            'editor_unlink' => 'Quitar enlace',
            'editor_clear' => 'Quitar formato',
            'editor_undo' => 'Deshacer',
            'editor_redo' => 'Rehacer',
            'editor_url' => 'Dirección',
            'editor_url_placeholder' => 'https://',
            'editor_apply' => 'Aplicar',
            'editor_image' => 'Imagen',
            'editor_image_uploading' => 'Subiendo imagen…',
            'editor_image_type' => 'Ese tipo de archivo no se admite.',
            'editor_image_size' => 'La imagen supera :max KB.',
            'editor_image_error' => 'No se pudo subir la imagen.',
            'editor_pre' => 'Bloque de código',
            'editor_left' => 'Alinear a la izquierda',
            'editor_center' => 'Centrar',
            'editor_right' => 'Alinear a la derecha',
            'editor_fullscreen' => 'Pantalla completa',
            // Mensajes de las reglas de validación del servidor.
            'editor_html_rechazado' => 'El contenido tiene marcado que no se admite.',
            'editor_max_texto' => 'El campo :attribute no puede pasar de :max caracteres.',

            'increment' => 'Sumar',
            'decrement' => 'Restar',
            'clear' => 'Limpiar',
            'remove' => 'Quitar',
            'remove_tag' => 'Quitar etiqueta',
            'remove_file' => 'Quitar archivo',
            'retry_upload' => 'Reintentar la subida',
            'dismiss_error' => 'Descartar el aviso',
            'reorder' => 'Arrastrar para reordenar',
            'previous_month' => 'Mes anterior',
            'next_month' => 'Mes siguiente',
            'previous_decade' => 'Década anterior',
            'next_decade' => 'Década siguiente',
            'toggle_period' => 'Cambiar entre AM y PM',
            'increment_hour' => 'Subir la hora',
            'decrement_hour' => 'Bajar la hora',
            'increment_minute' => 'Subir los minutos',
            'decrement_minute' => 'Bajar los minutos',
            'choose_date' => 'Elegir fecha',
            'choose_time' => 'Elegir hora',
            'pick_color' => 'Elegir color',
            'digit' => 'Dígito',
            'rating' => 'Valoración',
            'range_from' => 'Desde',
            'range_to' => 'Hasta',
            'range_min' => 'mínimo',
            'range_max' => 'máximo',
            // El nombre de cada estrella, entero: el «de» que las separaba
            // estaba escrito en la vista, y un conector suelto no se traduce.
            'rating_stars' => ':n de :total estrellas',
            'custom_color' => 'Color personalizado',

            // El medidor de fuerza de `password`. Vivían dentro de
            // `resources/js/form/password.js`, en inglés: el único texto de la
            // librería que no se podía cambiar ni publicando las vistas, porque
            // hacía falta recompilar el bundle.
            'password_show' => 'Mostrar la contraseña',
            'password_hide' => 'Ocultar la contraseña',
            'password_weak' => 'Débil',
            'password_fair' => 'Regular',
            'password_good' => 'Buena',
            'password_strong' => 'Fuerte',
            'password_rule_length' => 'Al menos :min caracteres',
            'password_rule_uppercase' => 'Una letra mayúscula',
            'password_rule_lowercase' => 'Una letra minúscula',
            'password_rule_number' => 'Un número',
            'password_rule_special' => 'Un carácter especial',

            // Textos visibles. Estaban escritos en inglés dentro de las vistas,
            // mientras el resto de la librería respondía en español: una misma
            // pantalla mezclaba «Añadir» con «No options found». Aquí se pueden
            // cambiar sin publicar las vistas.
            'loading' => 'Cargando…',
            'no_options' => 'Sin resultados',
            'search' => 'Buscar',
            'choose_file' => 'Elegir archivo',
            'retry' => 'Reintento',
            'invalid_type' => '«{name}» no es un tipo de archivo admitido.',
            'invalid_size' => '«{name}» ({size}) supera el límite de {maxSize}.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Component Defaults
    |--------------------------------------------------------------------------
    |
    | Default configuration for general UI components (button, alert, badge,
    | card, dropdown, tooltip, avatar, loading).
    |
    */
    'ui' => [
        'size' => 'md',

        /*
         * Aspecto de TODAS las superficies de la librería: tarjetas, tablas,
         * métricas, barras, listas de descripción.
         *
         * `null` significa «no opino», que es como viene de fábrica: cada
         * componente conserva el aspecto que ya tenía. Poner `false` en una de
         * estas banderas la apaga en toda la librería de una vez —un diseño
         * plano sin ir etiqueta por etiqueta—, y la sección del componente de
         * más abajo puede devolvérsela a uno concreto.
         *
         * El orden manda así: el prop de la etiqueta, luego el ajuste del
         * componente, luego esto, y por último el defecto del propio componente.
         * Ver `KoreUi\Core\Support\Look`.
         */
        'look' => [
            'bordered' => null,
            'shadow' => null,
            'padding' => null,
            'compact' => null,
        ],

        // Mismo motivo que en `form.translations`: nombre accesible de los
        // controles que solo llevan un icono.
        'translations' => [
            'remove' => 'Quitar',
            // La barra del splitter es enfocable (`role="separator"` con
            // tabindex), así que necesita nombre: sin él un lector la anuncia
            // como «separador» y nada más.
            'resize' => 'Redimensionar paneles',
            // Nombre del `role="tree"` y verbos de sus chevrones: sin ellos, un
            // lector anuncia «árbol» y luego «Toggle expand» una vez por rama,
            // sin decir de cuál.
            'tree' => 'Árbol',
            'tree_expand' => 'Abrir',
            'tree_collapse' => 'Cerrar',

            // Carrusel. El `role="region"` necesita nombre —sin él no se anuncia
            // como región—, y el botón de parar existe porque WCAG 2.2.2 pide
            // poder detener cualquier movimiento automático: pausar al pasar el
            // ratón por encima no sirve a quien no usa ratón.
            'carousel' => 'Carrusel',
            'carousel_previous' => 'Diapositiva anterior',
            'carousel_next' => 'Diapositiva siguiente',
            'carousel_go_to' => 'Ir al grupo',
            'carousel_pause' => 'Parar el carrusel',
            'carousel_play' => 'Reanudar el carrusel',

            // Copiar al portapapeles. Los tres formatos del componente llevan un
            // botón que solo tiene un icono, y ninguno tenía nombre: el de la
            // variante `icon` se quedaba en un `title`, que no basta.
            'copy' => 'Copiar',
            'copied' => 'Copiado',

            // Menú desplegable: `role="menu"` sin nombre no dice de qué es.
            'menu' => 'Menú',
            'speed_dial' => 'Acciones rápidas',

            // Nombre por defecto de un grupo de botones.
            'button_group' => 'Acciones',

            // Cierre de una pestaña descartable.
            'close_tab' => 'Cerrar pestaña',

            // Migas de pan: el nombre del `<nav>`, que es lo que un lector
            // anuncia antes de leer la ruta.
            'breadcrumb' => 'Ruta de navegación',
            'breadcrumb_expand' => 'Mostrar los pasos ocultos',

            // Doble lista.
            'transfer_move_all' => 'Mover todo a seleccionados',
            'transfer_move' => 'Mover seleccionados',
            'transfer_take' => 'Quitar seleccionados',
            'transfer_take_all' => 'Quitar todo',
            'transfer_search_placeholder' => 'Buscar…',

            // Listas reordenables.
            'drag' => 'Arrastrar',
            'move_up' => 'Subir',
            'move_down' => 'Bajar',
            'reorder' => 'Arrastrar para reordenar',
            'remove_row' => 'Eliminar fila',

            // Navegación, avisos y búsqueda rápida.
            'close_nav' => 'Cerrar navegación',
            'notifications' => 'Notificaciones',
            'close_notification' => 'Cerrar notificación',
            'spotlight' => 'Búsqueda rápida',
            'spotlight_search' => 'Buscar',
            'spotlight_results' => 'Resultados',
            'spotlight_remove_step' => 'Quitar :paso',
            'funnel' => 'Embudo de conversión',

            // Barra lateral. El nombre del `<nav>` estaba además en inglés:
            // «Sidebar» es lo que un lector anunciaba antes de leer el menú.
            'sidebar' => 'Navegación',
            'skip_to_content' => 'Saltar al contenido',
            // Nombre de respaldo de un paso sin etiqueta: el botón solo enseña
            // un número, y «1, botón» no dice nada.
            'step' => 'Paso',
            'sidebar_toggle' => 'Alternar navegación',

            // Filas que se añaden y se quitan (key-value, repeater).
            'add' => 'Añadir',
            'key' => 'Clave',
            'value' => 'Valor',

            // Filtro del árbol. Solo tenía `placeholder`, y eso desaparece en
            // cuanto se escribe algo.
            'tree_filter' => 'Filtrar…',

            // Selector de tema. Los tres modos y los nombres de sus dos
            // formatos: el interruptor de dos estados y el menú de tres.
            'theme' => 'Tema',
            'theme_toggle' => 'Cambiar entre claro y oscuro',
            'theme_light' => 'Claro',
            'theme_dark' => 'Oscuro',
            'theme_system' => 'Sistema',

            // `aria-roledescription` NO es un rol: es texto que el lector
            // PRONUNCIA, así que va en el idioma del contenido. Decía
            // «carousel» y «slide», y eso es lo que se oía.
            'carousel_role' => 'carrusel',
            'slide_role' => 'diapositiva',

            // Cierre de una alerta descartable.
            'close' => 'Cerrar',

            // Lo que anuncia un indicador de carga sin texto visible: sin esto,
            // la animación era la única señal de que algo estaba pasando.
            'loading' => 'Cargando',

            // Estado de un booleano y presencia de un avatar. El booleano decía
            // literalmente «true» y «false», en inglés y sin significado.
            'yes' => 'Sí',
            'no' => 'No',
            'presence_online' => 'En línea',
            'presence_offline' => 'Desconectado',
            'presence_busy' => 'Ocupado',
            'presence_away' => 'Ausente',

            // Doble lista. Las cajas de búsqueda solo tenían `placeholder`, que
            // desaparece en cuanto se escribe algo.
            'transfer_search' => 'Buscar en :panel',
            'transfer_select' => 'Elegir :item',
        ],

        'button' => [
            'variant' => 'solid',
            'color' => 'primary',
        ],
        'alert' => [
            'variant' => 'soft',
        ],
        'badge' => [
            'variant' => 'soft',
        ],
        'descriptions' => [
            'columns' => 1,
            'layout' => 'horizontal',  // horizontal|vertical
            'bordered' => false,
            'size' => 'md',            // sm|md|lg
        ],
        'result' => [
            'status' => 'info',  // success|info|warning|error|404|403|500
        ],
        'sortable' => [
            'mode' => 'server',  // server (wire:sort) | client (x-sort)
            'handle' => false,
            'animation' => 150,
        ],
        'transfer' => [
            'searchable' => true,
        ],
        'order-list' => [
            'reorderable' => true,
        ],
        // El texto enriquecido ya publicado: <x-kore::prose>.
        'prose' => [
            'size' => 'md',
        ],
        'card' => [
            // `null` = lo que diga `ui.look`, y si tampoco opina, el defecto del
            // componente. Un `true` o un `false` aquí solo afectan a las tarjetas.
            'bordered' => null,
            'shadow' => null,
            'padding' => null,
        ],
        'dropdown' => [
            'position' => 'bottom-start',
            'width' => 'auto',
        ],
        'tooltip' => [
            'position' => 'top',
            'delay' => 200,
        ],
        'avatar' => [
            'shape' => 'circle',
        ],
        'loading' => [
            'type' => 'spinner',
        ],
        'page-loading' => [
            'type' => 'spinner',
            'blur' => true,
            'text' => null,
        ],
        'divider' => [
            'type' => 'solid',
        ],
        'progress' => [
            'size' => 'md',
            'color' => 'primary',
        ],
        'toolbar' => [
            'variant' => 'default',
        ],
        'accordion' => [
            'variant' => 'bordered',
        ],
        'tab' => [
            'variant' => 'line',
        ],
        'stepper' => [
            'variant' => 'horizontal',
            'linear' => false,
        ],
        'skeleton' => [
            'animation' => 'shimmer',  // shimmer|pulse|none
        ],
        'chip' => [
            'variant' => 'soft',
        ],
        'timeline' => [
            'variant' => 'left',
        ],
        'stats' => [
            'variant' => 'default',
            'animated' => true,
        ],
        'clipboard' => [
            'variant' => 'input',
        ],
        'kbd' => [
            'size' => 'md',
        ],
        'boolean' => [
            'true_icon' => 'check',
            'false_icon' => 'x',
            'true_color' => 'success',
            'false_color' => 'destructive',
        ],
        'speed-dial' => [
            'icon' => 'plus',
            'direction' => 'up',
            'size' => 'sm',
        ],
        'splitter' => [
            'orientation' => 'horizontal',
            'gutter_size' => 8,
        ],
        'carousel' => [
            'autoplay' => false,
            'interval' => 5000,
            'loop' => true,
            'pause_on_hover' => true,
            'show_indicators' => true,
            'show_navigation' => true,
            'num_visible' => 1,
            'gap' => 16,
        ],
        'icon' => [
            'prefix' => 'lucide',
            'size' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | Configuration for the breadcrumb navigation component and its
    | PHP registration system.
    |
    */
    'breadcrumbs' => [
        // Set to false to disable the breadcrumb registration system entirely.
        // Useful when using another breadcrumbs library (e.g. diglactic/laravel-breadcrumbs).
        // The <x-kore::breadcrumbs /> component still works with manual :items.
        'enabled' => true,

        // File(s) where breadcrumbs are defined. Supports string or array of paths.
        // Uses a kore-specific filename to avoid conflicts with other libraries.
        'files' => base_path('routes/kore-breadcrumbs.php'),

        'separator' => 'icon:chevron-right',
        'size' => 'md',
        'json_ld' => false,
        'exceptions' => [
            'missing' => true,
            'unnamed' => true,
            'duplicate' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | DataTable Defaults
    |--------------------------------------------------------------------------
    |
    | Default configuration for the DataTable component (sorting, search,
    | pagination, density).
    |
    */
    'datatable' => [
        'per_page' => 25,
        'per_page_options' => [10, 25, 50, 100],
        'density' => 'normal',      // compact|normal|relaxed
        'table_layout' => 'auto',       // auto|fixed — 'fixed' hace exactos los width() de columna
        'pagination_type' => 'standard',    // standard|simple|cursor

        // Cómo se PINTA el pie, que es otra cosa que `pagination_type`: ese
        // decide cómo se consulta la base de datos, este solo la forma del
        // control. `default` (flechas y números), `simple` (anterior/siguiente
        // con palabras), `compact` (un carril con «3 / 12», que ocupa lo mismo
        // con dos mil páginas) o `minimal` (números sin cajas, el actual
        // subrayado). Cada tabla puede decidir la suya con `$paginatorVariant`.
        'paginator' => 'default',     // default|simple|compact|minimal
        'search_debounce' => 300,
        'filter_layout' => 'popover',     // popover|slide-down|inline|drawer
        'column_select' => true,
        'column_menu' => true,     // menú por cabecera: ordenar, fijar, ocultar
        'saved_views' => false,    // vistas guardadas por usuario (driver: sesión)
        'responsive_mode' => 'scroll',      // scroll|collapse|card
        'responsive_breakpoint' => 768,
        'query_string' => false,          // persist state in URL
        'export' => [
            'enabled' => false,
            'formats' => ['csv'],
            'max_rows' => 10000,
        ],
        'deferred_loading' => false,
        'empty_text' => 'No se encontraron resultados',
        'empty_icon' => 'inbox',
        'translations' => [
            'search' => 'Buscar...',
            'per_page' => 'Por página',
            'showing' => 'Mostrando :from a :to de :total resultados',
            'filters' => 'Filtros',
            'actions' => 'Acciones',
            'selected' => 'seleccionado(s)',
            'clear_filters' => 'Limpiar filtros',
            'apply' => 'Aplicar',
            'columns' => 'Columnas',
            'reset_columns' => 'Restablecer',
            'column_options' => 'Opciones de columna',
            'sort_asc' => 'Ordenar ascendente',
            'sort_desc' => 'Ordenar descendente',
            'clear_sort' => 'Quitar orden',
            'pin_left' => 'Fijar a la izquierda',
            'pin_right' => 'Fijar a la derecha',
            'unpin' => 'Soltar columna',
            'hide_column' => 'Ocultar columna',
            'views' => 'Vistas',
            'no_views' => 'Aún no has guardado ninguna vista.',
            'save_current_view' => 'Guardar la vista actual',
            'view_name' => 'Nombre',
            'save' => 'Guardar',
            'delete_view' => 'Eliminar vista',
            'clear_view' => 'Salir de la vista',
            'sorted_by' => 'Ordenado por',
            'clear_sorts' => 'Limpiar ordenamiento',
            // Nombres accesibles con la columna dentro. El texto de fuera estaba
            // escrito en la vista: la interpolación de al lado lo escondía del
            // cepo, y «Ordenar por» no se podía traducir.
            'sort_by' => 'Ordenar por :columna',
            'remove_filter' => 'Quitar el filtro :filtro',
            'remove_sort' => 'Quitar el orden :columna',
            'expand_row' => 'Ver el resto de la fila',
            'copy' => 'Copiar',

            // Nombres accesibles que estaban escritos dentro de las vistas y no
            // se podían traducir sin publicarlas.
            'select_all' => 'Seleccionar todo',
            'select_row' => 'Seleccionar fila',
            'pagination' => 'Paginación',
            'previous' => 'Anterior',
            'next' => 'Siguiente',
            'clear_preset' => 'Limpiar el filtro guardado',
            'export' => 'Exportar',
            'export_truncated' => 'La exportación se limitó a las primeras :max filas.',
            'cancel' => 'Cancelar',
            'close' => 'Cerrar',
            'edit_unauthorized' => 'No se pudo actualizar el registro.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spotlight (Command Palette)
    |--------------------------------------------------------------------------
    |
    | Configuration for the Spotlight / command palette component.
    | Activated with Cmd+K / Ctrl+K.
    |
    */
    'spotlight' => [
        // PHP provider classes that supply spotlight items
        'providers' => [],

        // Keyboard shortcut key combined with Cmd (Mac) or Ctrl (Windows/Linux)
        'shortcut' => 'k',

        // Placeholder text shown in the search input
        'placeholder' => 'Buscar acciones, páginas...',

        // Show provider items when input is empty (before typing)
        'show_results_without_input' => true,

        // Show recent history section when input is empty
        'show_recent' => true,

        // Number of recent items to show (from localStorage)
        'recent_count' => 5,

        // Maximum history entries persisted in localStorage
        'max_history' => 10,

        // Global remote search endpoint (optional, can be overridden per-component)
        'search_url' => null,

        // HTTP method for remote search requests
        'search_method' => 'GET',

        // Debounce delay in milliseconds for remote search
        'debounce' => 300,

        // Maximum total results to display (local + remote)
        'max_results' => 50,

        // z-index for the spotlight panel (above overlays z-50 and feedback z-60)
        'z_index' => 'z-[70]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme Defaults
    |--------------------------------------------------------------------------
    |
    | Configuration for the theme switcher component and anti-FOUC script.
    |
    */
    'theme' => [
        'default' => 'system',  // 'light', 'dark', 'system'
        'nonce' => null,         // CSP nonce for the anti-FOUC inline script
    ],

    /*
    |--------------------------------------------------------------------------
    | Chart
    |--------------------------------------------------------------------------
    |
    | Defaults for the chart module.
    |
    | La geometría se calcula en PHP y el SVG se renderiza en el servidor. Eso hace que el
    | morph de Livewire no sea una amenaza sino el mecanismo de actualización, que el cambio
    | de tema no ejecute JavaScript (los colores son tokens --kore-chart-*, y el navegador
    | los resuelve solo), y que el resize no necesite JS en absoluto.
    |
    */
    'chart' => [
        'height' => '16rem',        // longitud CSS. Con la prop `aspect` puesta, se ignora
        'ticks' => 5,               // una PISTA: el algoritmo devuelve entre count/2 y count*2
        'bar_padding' => 0.2,       // proporción de la banda que queda libre entre barras

        // ⚠️ Estos dos NO son lo mismo, aunque los dos hablen del eje X.
        //
        // `max_x_labels` es un TOPE, y solo aplica a un eje de CATEGORÍAS: hay N categorías y se
        // pintan como mucho estas, saltando el resto. Rotarlas no es opción: transform:rotate()
        // no ocupa layout y se saldrían de la caja.
        //
        // `x_ticks` es un OBJETIVO, y aplica a un eje CONTINUO (fechas o números): ahí no hay
        // categorías que saltar, hay ticks que elegir. Pedir doce para un rango de una semana da
        // uno cada doce horas y se pisan unos con otros — por eso el defecto es más bajo.
        // Se sobrescribe por gráfico con <x-kore::chart.axis-x :ticks="8">.
        'max_x_labels' => 12,
        'x_ticks' => 6,
        'table_max_rows' => 500,    // tope de la tabla accesible; sin él, 10.000 filas en el DOM

        // Al posarse sobre un arco del donut se enciende también su fila de la leyenda, y al
        // revés. Es CSS puro (`:has()` sobre un `data-slice` común), así que apagarlo no
        // ahorra JavaScript: no había ninguno. Se apaga por gráfico con :highlight="false".
        'donut_highlight' => true,

        // Al posarse sobre una etapa de un embudo, se enciende el par (trapecio + fila) y se apaga
        // el resto. CSS puro, como el donut; se apaga por gráfico con :highlight="false".
        'funnel_highlight' => true,

        'empty_text' => 'No hay datos que mostrar',
        'empty_icon' => 'chart-line',

        'format' => [
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'currency' => '€',
            'currency_after' => true,   // "12 €" (es) vs "$12" (en)
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | App Shell
    |--------------------------------------------------------------------------
    |
    | Defaults for the application chrome: shell layout, sidebar and navbar.
    |
    | The collapsed/expanded state is persisted in the `kore_sidebar` cookie (not
    | localStorage) so the server can render the correct width on the first paint,
    | with no layout flash. Widths are CSS lengths, not Tailwind classes: they feed
    | the --kore-sidebar-* custom properties that drive the layout.
    |
    */
    'shell' => [
        'sidebar' => [
            'collapsible' => true,
            'collapsed' => false,        // initial state on first visit (before any cookie)
            'placement' => 'left',       // 'left' | 'right'
            'width' => '16rem',
            'collapsed_width' => '4rem',
            'breakpoint' => 'lg',        // 'sm' | 'md' | 'lg' | 'xl' — below this it becomes a drawer
            'persist' => true,
            'smart' => true,             // auto-detect the active route
            'navigate' => false,         // add wire:navigate to item links
            'overlay' => true,           // on mobile, show a backdrop behind the drawer
            'rail' => false,             // icons only, expands over the content on hover
            'expand_on_hover' => false,
            'duration' => 200,           // ms — collapse/expand transition

            // Collapsed, a numeric badge shrinks to a counter on the corner of the icon.
            // Anything above this shows as "99+": a four-digit count would be unreadable
            // at that size and would blow the icon's bounding box apart.
            'badge_max' => 99,
        ],

        'navbar' => [
            'sticky' => true,
            'bordered' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Kanban
    |--------------------------------------------------------------------------
    |
    | Board of columns with cards draggable within and between columns. Cards
    | are dragged with Alpine's x-sort (bundled with Livewire 4); the shared
    | 'group' lets a card move across columns.
    |
    */
    'kanban' => [
        'group' => 'kanban',
        'animation' => 150,
        'column_width' => '18rem',
    ],

];
