<?php
/**
 * lang/es.php — Strings en español (idioma por defecto)
 */
return [
    // ── Login ────────────────────────────────────────────────
    'page_title'           => 'Ingresar — Local App',
    'sign_in'              => 'Ingresar',
    'sign_in_subtitle'     => 'Ingrese su usuario y clave para continuar',
    'username_label'       => 'Usuario',
    'username_placeholder' => 'usuario o correo',
    'password_label'       => 'Clave',
    'show_password'        => 'Mostrar clave',
    'hide_password'        => 'Ocultar clave',
    'btn_sign_in'          => 'Ingresar',
    'forgot_password'      => '¿Olvidó su clave?',
    'language_label'       => 'Idioma',

    // ── Login errors ─────────────────────────────────────────
    'error_empty'          => 'Por favor ingrese su usuario y clave.',
    'error_invalid'        => 'Usuario o clave incorrectos. Intente de nuevo.',
    'error_inactive'       => 'Su cuenta está inactiva. Contacte al administrador.',
    'error_locked'         => 'Cuenta bloqueada por intentos fallidos. Puede intentarlo de nuevo en %s minuto(s).',
    'error_timeout'        => 'Su sesión fue cerrada por inactividad (30 minutos). Ingrese nuevamente.',
    'error_deactivated'    => 'Su cuenta fue desactivada. Contacte al administrador.',

    // ── Forgot password ───────────────────────────────────────
    'forgot_page_title'    => 'Solicitar clave — Local App',
    'forgot_title'         => 'Solicitar clave',
    'forgot_subtitle'      => 'Complete el formulario. El administrador del sistema le enviará su clave.',
    'company_label'        => 'Nombre de compañía',
    'company_placeholder'  => 'Mi Empresa S.A.',
    'email_label'          => 'Correo electrónico',
    'email_placeholder'    => 'correo@empresa.com',
    'opt_user_label'       => 'Usuario (si lo recuerda)',
    'opt_user_placeholder' => 'usuario',
    'notes_label'          => 'Información adicional (opcional)',
    'btn_request'          => 'Solicitar clave',
    'btn_back'             => 'Regresar',
    'forgot_success'       => 'Su solicitud fue enviada al administrador. Pronto recibirá respuesta en su correo.',
    'forgot_error_empty'   => 'El nombre de compañía y el correo electrónico son requeridos.',
    'forgot_error_email'   => 'Ingrese un correo electrónico válido.',

    // ── Shared dashboard ─────────────────────────────────────
    'sign_out'             => 'Cerrar sesión',
    'session_active'       => 'Sesión activa',
    'signed_in_at'         => 'Ingresó el',
    'user_id_label'        => 'ID de usuario',
    'role_label'           => 'Rol',
    'role_admin'           => 'Administrador',
    'role_supplier'        => 'Proveedor',
    'role_owner'           => 'Propietario',
    'role_user'            => 'Usuario',
    'welcome'              => 'Bienvenido',

    // ── Owner panel ───────────────────────────────────────────
    'owner_page_title'     => 'Panel de Propietario — Local App',
    'owner_title'          => 'Administración de negocio',
    'btn_set_role'         => 'Asignar rol',
    'feedback_activated'   => 'Usuario activado.',
    'feedback_deactivated' => 'Usuario desactivado.',
    'feedback_unlocked'    => 'Cuenta desbloqueada.',
    'feedback_role_changed'   => 'Rol actualizado.',
    'feedback_request_resolved' => 'Solicitud marcada como resuelta.',

    // ── User dashboard ────────────────────────────────────────
    'user_page_title'     => 'Panel de Usuario — Local App',
    'user_title'          => 'Mi cuenta',
    'user_welcome'        => 'Bienvenido/a, %s',
    'user_session_info'   => 'Has iniciado sesión correctamente.',
    'user_idle_notice'    => 'Tu sesión cerrará automáticamente tras %d minutos de inactividad.',

    // ── Tab navigation ────────────────────────────────────────
    'tab_nav_label'  => 'Secciones',
    'tab_coming_soon'=> 'Próximamente',
    // Supplier tabs
    'tab_profile'    => 'Mi Perfil',
    'tab_summary'    => 'Resumen',
    'tab_documents'  => 'Documentos',
    'tab_orders'     => 'Pedidos',
    // Admin / Owner tabs
    'tab_users'      => 'Usuarios',
    'tab_reports'    => 'Reportes',
    'tab_settings'   => 'Configuración',
    // User tabs
    'tab_dashboard'  => 'Mi cuenta',
    'tab_history'    => 'Historial',

    // ── Admin ─────────────────────────────────────────────────
    'admin_page_title'     => 'Administración — Local App',
    'admin_title'          => 'Administración del sistema',
    'admin_subtitle'       => 'Panel principal de administración.',
    'user_management'      => 'Gestión de usuarios',
    'col_id'               => 'ID',
    'col_username'         => 'Usuario',
    'col_email'            => 'Correo',
    'col_role'             => 'Rol',
    'col_status'           => 'Estado',
    'col_first_login'      => 'Primer ingreso',
    'col_attempts'         => 'Intentos fallidos',
    'col_locked_until'     => 'Bloqueado hasta',
    'col_actions'          => 'Acciones',
    'status_active'        => 'Activo',
    'status_inactive'      => 'Inactivo',
    'first_login_yes'      => 'Pendiente',
    'first_login_no'       => 'Completado',
    'btn_activate'         => 'Activar',
    'btn_deactivate'       => 'Desactivar',
    'btn_unlock'           => 'Desbloquear',
    'col_requests'         => 'Solicitudes de clave',
    'req_company'          => 'Compañía',
    'req_email'            => 'Correo',
    'req_user'             => 'Usuario',
    'req_notes'            => 'Notas',
    'req_date'             => 'Fecha',
    'req_status'           => 'Estado',
    'req_pending'          => 'Pendiente',
    'req_resolved'         => 'Resuelta',
    'btn_resolve'          => 'Marcar resuelta',
    'no_requests'          => 'No hay solicitudes pendientes.',
    'no_users'             => 'No hay usuarios registrados.',

    // ── Supplier profile ──────────────────────────────────────
    'profile_page_title'   => 'Perfil del proveedor — Local App',
    'profile_title'        => 'Perfil del proveedor',
    'profile_subtitle'     => 'Complete su perfil para continuar al sistema.',
    'btn_save'             => 'Guardar perfil',
    'profile_success'      => 'Perfil guardado correctamente.',
    'profile_error_fields' => 'Por favor corrija los campos marcados en rojo.',

    // ── Sección: Información General ─────────────────────────
    'section_general'           => 'Información General',
    'full_name_label'           => 'Nombre del contacto principal',
    'full_name_placeholder'     => 'Juan Pérez',
    'company_name_label'        => 'Nombre legal / Razón social',
    'company_name_ph'           => 'Mi Empresa S.A. de C.V.',
    'company_name_help'         => 'Tal como aparece en el registro legal de la empresa.',

    // ── Sección: Información Legal ────────────────────────────
    'section_legal'             => 'Información Legal',
    'tax_id_label'              => 'Identificación legal (RUC / NIT / Cédula jurídica)',
    'tax_id_placeholder'        => '3-101-000000',
    'legal_rep_name_label'      => 'Nombre del representante legal',
    'legal_rep_name_placeholder'=> 'María González',
    'legal_rep_id_label'        => 'Identificación del representante legal',
    'legal_rep_id_placeholder'  => '1-0000-0000',
    'company_phone_label'       => 'Teléfono de la compañía',
    'legal_rep_phone_label'     => 'Teléfono del representante (opcional)',
    'phone_code_placeholder'    => 'Código',
    'phone_number_placeholder'  => 'Número',

    // ── Sección: Dirección Oficina Principal ──────────────────
    'section_addr_company'      => 'Dirección de Oficina Principal',
    'addr_street_label'         => 'Calle / Avenida / Número',
    'addr_street_placeholder'   => 'Av. Central 100, Edif. Torre Norte, Piso 3',
    'addr_city_label'           => 'Ciudad',
    'addr_city_placeholder'     => 'San José',
    'addr_state_label'          => 'Estado / Provincia / Departamento',
    'addr_state_placeholder'    => 'San José',
    'addr_zip_label'            => 'Código postal',
    'addr_zip_placeholder'      => '10101',
    'addr_country_label'        => 'País',
    'addr_country_default'      => '-- Seleccione un país --',

    // ── Sección: Dirección de Fábrica ─────────────────────────
    'section_addr_factory'      => 'Dirección de Fábrica (opcional)',
    'factory_street_label'      => 'Calle / Avenida / Número',
    'factory_street_placeholder'=> 'Zona Industrial, Lote 5',
    'factory_city_label'        => 'Ciudad',
    'factory_city_placeholder'  => 'Cartago',
    'factory_state_label'       => 'Estado / Provincia / Departamento',
    'factory_state_placeholder' => 'Cartago',
    'factory_zip_label'         => 'Código postal',
    'factory_zip_placeholder'   => '30101',
    'factory_country_label'     => 'País',
    'factory_country_default'   => '-- Seleccione un país --',

    // ── Sección: Contactos ────────────────────────────────────
    'section_contacts'          => 'Listado de Contactos',
    'contacts_subtitle'         => 'Agregue las personas de contacto de su organización.',
    'col_contact_name'          => 'Nombre',
    'col_contact_role'          => 'Cargo / Área',
    'col_contact_email'         => 'Correo',
    'col_contact_phone'         => 'Teléfono',
    'col_contact_primary'       => 'Principal',
    'col_contact_actions'       => 'Acciones',
    'contact_yes'               => 'Sí',
    'contact_no'                => '—',
    'btn_add_contact'           => 'Agregar contacto',
    'btn_delete'                => 'Eliminar',
    'contact_name_label'        => 'Nombre *',
    'contact_name_placeholder'  => 'Ana Martínez',
    'contact_role_label'        => 'Cargo / Área',
    'contact_role_placeholder'  => 'Gerente de compras',
    'contact_email_label'       => 'Correo electrónico',
    'contact_email_placeholder' => 'ana@empresa.com',
    'contact_phone_label'       => 'Teléfono',
    'contact_primary_label'     => 'Marcar como contacto principal',
    'no_contacts'               => 'Aún no hay contactos registrados.',
    'contact_error_name'        => 'El nombre del contacto es requerido.',
    'contact_added'             => 'Contacto agregado correctamente.',
    'contact_deleted'           => 'Contacto eliminado.',

    // ── Botón Regresar ────────────────────────────────────────
    'btn_back'                  => 'Regresar',
    'phone_label'               => 'Teléfono',
    'phone_placeholder'         => '+506 8888-0000',

    // ── Supplier summary ──────────────────────────────────────
    'summary_page_title'   => 'Resumen del proveedor — Local App',
    'summary_title'        => 'Resumen del proveedor',
    'summary_subtitle'     => 'Bienvenido a su panel de proveedor.',
    'your_profile'         => 'Su perfil',
    'edit_profile'         => 'Editar perfil',
    'field_full_name'      => 'Nombre',
    'field_company'        => 'Compañía',
    'field_phone'          => 'Teléfono',
    'field_email'          => 'Correo',
    'not_provided'         => 'No indicado',    // ── Tab: Agregar producto ────────────────────────────────────────────
    'tab_add_product'           => 'Agregar producto',

    // ── Carga de productos ───────────────────────────────────────────────
    'add_product_page_title'    => 'Agregar producto — Local App',
    'add_product_title'         => 'Carga de productos',
    'add_product_subtitle'      => 'Complete el formulario para registrar un nuevo producto.',

    // Sección campos
    'section_product_info'      => 'Información del producto',
    'section_product_pricing'   => 'Precios (opcionales)',

    // Campos
    'field_supplier_code'       => 'Código producto proveedor',
    'field_supplier_code_ph'    => 'Ej. PROD-001',
    'field_supplier_code_help'  => 'Debe ser único dentro de su catálogo de productos.',
    'field_admin_code'          => 'Código producto administrador',
    'field_admin_code_ph'       => 'Ej. SKU-0001',
    'field_admin_code_help'     => 'Código global único asignado por el administrador.',
    'field_product_name'        => 'Nombre del producto',
    'field_product_name_ph'     => 'Nombre descriptivo del producto',
    'field_tech_desc'           => 'Descripción / Ficha Técnica',
    'field_tech_desc_ph'        => 'Ingrese descripción, características y detalles técnicos del producto...',
    'field_tech_desc_help'      => 'Puede incluir texto plano, bullets y títulos de sección. No se permiten links externos.',
    'field_price_fob'           => 'Precio Unitario FOB',
    'field_price_fob_ph'        => '0.00',
    'field_price_cif'           => 'Precio Unitario CIF',
    'field_price_cif_ph'        => '0.00',

    // Botones
    'btn_save_product'          => 'Guardar producto',
    'btn_cancel_product'        => 'Cancelar',

    // Mensajes de validación
    'err_supplier_code_required'  => 'El código del producto es obligatorio.',
    'err_product_name_required'   => 'El nombre del producto es obligatorio.',
    'err_supplier_code_duplicate' => 'Ya existe un producto con este código para este proveedor.',
    'err_admin_code_duplicate'    => 'Ya existe un producto con este código de administrador.',
    'err_price_fob_numeric'       => 'El precio FOB debe ser un número válido.',
    'err_price_cif_numeric'       => 'El precio CIF debe ser un número válido.',
    'err_desc_no_links'           => 'La descripción no puede contener links externos.',

    // Mensaje de éxito
    'product_saved'               => 'El producto fue guardado correctamente.',

    // Imágenes del producto
    'section_product_images'      => 'Imágenes del producto',
    'img_aerial_label'            => 'Vista aérea',
    'img_lateral_front_label'     => 'Vista frontal',
    'img_lateral_back_label'      => 'Vista trasera',
    'img_lateral_left_label'      => 'Vista lateral izquierda',
    'img_lateral_right_label'     => 'Vista lateral derecha',
    'img_click_to_upload'         => 'Haga clic para seleccionar imagen',
    'img_allowed_types'           => 'JPG, PNG, WEBP, GIF, BMP, AVIF · máx. 5 MB',
    'img_remove'                  => 'Eliminar',
    'err_img_required'            => 'Debe subir al menos la imagen de vista aérea.',
    'err_img_type'                => 'Solo se permiten imágenes JPG, PNG o WEBP.',
    'err_img_size'                => 'El archivo no debe superar 5 MB.',
    'err_img_upload_failed'       => 'Error al subir la imagen. Intente de nuevo.',
    'err_product_save_failed'     => 'Error al guardar el producto. Intente de nuevo.',

    // Mis productos (listado)
    'tab_my_products'             => 'Mis productos',
    'my_products_page_title'      => 'Mis productos — Local App',
    'my_products_title'           => 'Mis productos',
    'my_products_subtitle'        => 'Catálogo de productos registrados.',
    'no_products'                 => 'Aún no ha registrado ningún producto.',
    'btn_view_product'            => 'Ver detalle',
    'btn_add_new_product'         => 'Agregar nuevo producto',
    'col_product_code'            => 'Código',
    'col_product_name'            => 'Nombre del producto',
    'col_product_price_fob'       => 'Precio FOB',
    'col_product_price_cif'       => 'Precio CIF',
    'col_product_date'            => 'Fecha',
    'col_product_images'          => 'Imágenes',
    'col_actions'                 => 'Acciones',

    // Detalle de producto
    'product_view_page_title'     => 'Detalle del producto — Local App',
    'product_view_title'          => 'Detalle del producto',
    'product_not_found'           => 'Producto no encontrado.',
    'section_product_gallery'     => 'Galería de imágenes',
    'no_images'                   => 'Sin imágenes registradas.',
    'img_slot_aerial'             => 'Vista aérea',
    'img_slot_lateral_front'      => 'Vista frontal',
    'img_slot_lateral_back'       => 'Vista trasera',
    'img_slot_lateral_left'       => 'Vista lateral izquierda',
    'img_slot_lateral_right'      => 'Vista lateral derecha',
    'btn_back_to_products'        => 'Volver a mis productos',
    'product_view_required'       => '* Requerida',
    'product_view_optional'       => 'Opcional',
    // Tabs de detalle de producto
    'tab_product_detail'          => 'Detalle',
    'tab_product_edit'            => 'Editar',
    // Editar producto
    'product_updated'             => 'Producto actualizado correctamente.',
    'err_product_update_failed'   => 'Error al actualizar el producto. Intente de nuevo.',
    'edit_img_delete'             => 'Eliminar imagen actual',
    'edit_img_or_replace'         => 'O subir una de reemplazo:',
    'edit_img_click_zoom'         => 'Clic para ampliar',
    // ── Multi-org / Org picker ─────────────────────────────────────
    'error_no_org'         => 'Su cuenta no tiene acceso a ninguna organización. Contacte al administrador.',
    'error_org_invalid'    => 'Selección de organización no válida. Intente de nuevo.',
    'org_picker_title'     => 'Seleccionar organización — Local App',
    'org_picker_heading'   => '¿A cuál organización desea ingresar?',
    'org_picker_subtitle'  => 'Su cuenta “%s” tiene acceso a más de una organización.',
    'org_picker_cancel'    => 'Cancelar e ingresar de nuevo',
    'org_label'            => 'Organización',

    // ── Correo de contacto / Verificación de correo ───────────────────────
    'contact_email_field_label'   => 'Correo electrónico de contacto',
    'contact_email_field_ph'      => 'correo@empresa.com',
    'contact_email_field_help'    => 'Este correo reemplazará al correo de acceso tras verificación. Recibirá un código de 6 dígitos.',
    'email_invalid'               => 'Ingrese un correo electrónico válido.',

    // Banner de verificación en profile.php
    'email_verify_banner_title'   => 'Verificación de correo pendiente',
    'email_verify_banner_desc'    => 'Se envió un código de 6 dígitos a %s. Ingréselo a continuación para confirmar el cambio.',
    'email_verify_expires_in'     => 'Expira en: %s',
    'email_verify_code_label'     => 'Código de verificación (6 dígitos)',
    'email_verify_btn'            => 'Verificar correo',
    'email_verify_resend'         => 'Reenviar código',

    // Flash / mensajes POST→GET
    'email_verify_sent'           => 'Código de verificación enviado. Revise su correo e ingréselo abajo.',
    'email_verify_resent'         => 'Se reenvió el código de verificación.',
    'email_verify_already_pending'=> 'Ya hay un código de verificación activo para ese correo. Revise su bandeja de entrada.',
    'email_verified_success'      => '¡Correo actualizado correctamente! Su nuevo correo de acceso ha sido verificado.',

    // Errores del código
    'email_verify_no_pending'     => 'No hay un correo pendiente de verificación.',
    'email_verify_no_code'        => 'No se encontró un código de verificación activo.',
    'email_verify_expired'        => 'El código expiró. Reenvíe el código e intente de nuevo.',
    'email_verify_wrong_code'     => 'Código incorrecto. Verifique e intente de nuevo.',

    // Banner de expirado
    'email_verify_expired_title'  => 'Código de verificación expirado',
    'email_verify_expired_desc'   => 'El código enviado a %s expiró sin ser verificado. El perfil está guardado pero el correo no fue actualizado.',

    // Badges
    'email_badge_pending'         => 'Pendiente verificación',
    'email_badge_expired'         => 'Sin verificar',
    'btn_copy_code'               => 'Copiar',

    // Sección Borrador en summary.php
    'draft_section_title'         => 'Borrador',
    'draft_section_subtitle'      => 'Elementos guardados que requieren acción para completarse.',
    'draft_email_change_title'    => 'Cambio de correo sin completar',
    'draft_email_change_desc'     => 'Se intentó cambiar el correo a %s pero el código de verificación expiró.',
    'draft_email_change_hint'     => 'Haga clic en "Reenviar código" para recibir un nuevo código y completar el cambio.',
];
