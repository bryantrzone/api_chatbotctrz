```mermaid
erDiagram
    flujos ||--o{ nodos : contiene
    nodos ||--o{ nodo_contenido : tiene
    nodos ||--o{ transiciones : origen
    nodos ||--o{ transiciones : destino
    flujos ||--o{ sesiones : actual
    nodos ||--o{ sesiones : actual
    variables ||--o{ usuario_variables : instancia
    sesiones ||--o{ mensajes : registra
    sesiones ||--o{ asignaciones : asignado
    agentes ||--o{ asignaciones : atiende
    departamentos ||--o{ agente_departamento : pertenece
    agentes ||--o{ agente_departamento : asignado
    departamentos ||--o{ respuestas_predefinidas : tiene
    flujos ||--o{ reglas_enrutamiento : origen
    nodos ||--o{ reglas_enrutamiento : punto
    departamentos ||--o{ reglas_enrutamiento : destino

    flujos {
        int id PK
        string nombre
        string descripcion
        string trigger_palabra
        boolean es_default
        int orden
        boolean activo
        timestamp created_at
        timestamp updated_at
    }
    
    nodos {
        int id PK
        int flujo_id FK
        string nombre
        enum tipo
        boolean es_inicial
        boolean es_final
        int timeout
        int timeout_nodo_id FK
        text script
        timestamp created_at
        timestamp updated_at
    }
    
    nodo_contenido {
        int id PK
        int nodo_id FK
        enum tipo_contenido
        text contenido
        int orden
        int espera
        timestamp created_at
        timestamp updated_at
    }
    
    transiciones {
        int id PK
        int nodo_origen_id FK
        int nodo_destino_id FK
        text condicion
        string valor_esperado
        boolean es_default
        int orden
        timestamp created_at
        timestamp updated_at
    }
    
    variables {
        int id PK
        string nombre
        string descripcion
        text valor_default
        boolean es_global
        timestamp created_at
        timestamp updated_at
    }
    
    usuario_variables {
        int id PK
        string telefono
        int variable_id FK
        text valor
        timestamp created_at
        timestamp updated_at
    }
    
    sesiones {
        int id PK
        string telefono
        string nombre_usuario
        int flujo_actual_id FK
        int nodo_actual_id FK
        text ultimo_mensaje_recibido
        text ultimo_mensaje_enviado
        timestamp fecha_ultimo_mensaje
        enum estado
        int tiempo_inactividad
        timestamp created_at
        timestamp updated_at
    }
    
    mensajes {
        int id PK
        int sesion_id FK
        string telefono
        int flujo_id FK
        int nodo_id FK
        enum tipo
        enum tipo_contenido
        text contenido
        string whatsapp_message_id
        timestamp created_at
    }
    
    agentes {
        int id PK
        string nombre
        string email
        string password
        string telefono
        enum estado
        int capacidad_max
        timestamp ultimo_login
        timestamp created_at
        timestamp updated_at
    }
    
    asignaciones {
        int id PK
        int sesion_id FK
        int agente_id FK
        enum estado
        timestamp fecha_asignacion
        timestamp fecha_finalizacion
        timestamp created_at
        timestamp updated_at
    }
    
    departamentos {
        int id PK
        string nombre
        string descripcion
        boolean activo
        timestamp created_at
        timestamp updated_at
    }
    
    agente_departamento {
        int id PK
        int agente_id FK
        int departamento_id FK
        boolean es_supervisor
        timestamp created_at
        timestamp updated_at
    }
    
    reglas_enrutamiento {
        int id PK
        string nombre
        int flujo_id FK
        int nodo_id FK
        int departamento_id FK
        text condicion
        int prioridad
        boolean activo
        timestamp created_at
        timestamp updated_at
    }
    
    respuestas_predefinidas {
        int id PK
        int departamento_id FK
        string titulo
        text contenido
        string tags
        boolean activo
        timestamp created_at
        timestamp updated_at
    }