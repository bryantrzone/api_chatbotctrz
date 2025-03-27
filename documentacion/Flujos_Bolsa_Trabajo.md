```mermaid
flowchart TD
    inicio([Inicio Bolsa de Trabajo]) --> seleccion_sucursal[Selección de Sucursal]
    
    seleccion_sucursal --> obtener_sucursales[Consultar Sucursales\ncon Vacantes Activas]
    obtener_sucursales --> mostrar_sucursales[Mostrar Lista\nde Sucursales]
    
    mostrar_sucursales --> usuario_selecciona_sucursal{Usuario Selecciona\nSucursal}
    usuario_selecciona_sucursal --> seleccion_area[Selección de Área]
    
    seleccion_area --> obtener_areas[Consultar Áreas\nen la Sucursal]
    obtener_areas --> mostrar_areas[Mostrar Lista\nde Áreas]
    
    mostrar_areas --> usuario_selecciona_area{Usuario Selecciona\nÁrea}
    usuario_selecciona_area --> mostrar_vacantes[Mostrar Vacantes\nDisponibles]
    
    mostrar_vacantes --> usuario_selecciona_vacante{Usuario Selecciona\nVacante}
    usuario_selecciona_vacante -->|Ver Detalles| ver_detalles[Mostrar Detalles\nde Vacante]
    ver_detalles --> postularse{¿Desea\nPostularse?}
    
    usuario_selecciona_vacante -->|Seleccionar Directamente| postularse
    
    postularse -->|No| otras_vacantes[Mostrar Otras\nVacantes]
    postularse -->|Sí| inicio_postulacion[Iniciar Proceso\nde Postulación]
    
    inicio_postulacion --> pedir_nombre[Pedir Nombre]
    pedir_nombre --> recibir_nombre[Recibir Nombre]
    recibir_nombre --> pedir_edad[Pedir Edad]
    
    pedir_edad --> recibir_edad[Recibir Edad]
    recibir_edad --> validar_edad{¿Edad\nVálida?}
    validar_edad -->|No| pedir_edad
    validar_edad -->|Sí| pedir_experiencia[Pedir Experiencia]
    
    pedir_experiencia --> recibir_experiencia[Recibir Experiencia]
    recibir_experiencia --> pedir_email[Pedir Email]
    
    pedir_email --> recibir_email[Recibir Email]
    recibir_email --> validar_email{¿Email\nVálido?}
    validar_email -->|No| pedir_email
    validar_email -->|Sí| guardar_postulacion[Guardar Postulación\nen BD]
    
    guardar_postulacion --> pedir_cv[Pedir CV]
    pedir_cv --> esperar_cv{Esperar\nCV}
    
    esperar_cv -->|Envía CV| procesar_cv[Procesar CV]
    esperar_cv -->|Omitir| finalizar_sin_cv[Finalizar\nsin CV]
    
    procesar_cv --> guardar_cv[Guardar CV\ny Actualizar BD]
    guardar_cv --> confirmar_registro[Confirmar\nPostulación Completa]
    
    finalizar_sin_cv --> confirmar_registro
    
    confirmar_registro --> opciones_continuar[Opciones para\nContinuar]
    otras_vacantes --> mostrar_sucursales
    
    opciones_continuar -->|Ver Otras Vacantes| otras_vacantes
    opciones_continuar -->|Menú Principal| menu_principal[Volver al\nMenú Principal]