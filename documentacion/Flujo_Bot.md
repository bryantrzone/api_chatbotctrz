```mermaid
flowchart TD
    A[Recibir Mensaje de WhatsApp] --> B{Verificación\ndel Webhook}
    B -->|Token correcto| C[Extraer información\ndel mensaje]
    B -->|Token incorrecto| Z[Retornar error]
    
    C --> D[Buscar o crear\nsesión del usuario]
    D --> E{¿Tiene una\nsesión activa?}
    
    E -->|No| F[Determinar Flujo Inicial]
    E -->|Sí| G[Obtener estado actual\ndel flujo y nodo]
    
    F --> H[Ejecutar Nodo Inicial\ndel Flujo]
    G --> I[Evaluar Transiciones\nposibles]
    
    I --> J{¿Hay transición\nválida?}
    J -->|Sí| K[Seguir a nuevo nodo]
    J -->|No| L[Ejecutar transición\npor defecto]
    
    K --> M[Procesar Nodo]
    L --> M
    H --> M
    
    M --> N{Tipo de nodo}
    N -->|Mensaje| O[Enviar contenido\ndel nodo]
    N -->|Pregunta| P[Enviar pregunta y\nesperar respuesta]
    N -->|Decisión| Q[Evaluar condición\ny determinar camino]
    N -->|Operación| R[Ejecutar script\ndel nodo]
    N -->|Redirect| S[Cambiar a\notro flujo]
    N -->|Finalizar| T[Terminar conversación\nacutal]
    
    O --> U[Actualizar estado\ndel usuario]
    P --> U
    Q --> U
    R --> U
    S --> U
    T --> U
    
    U --> V{¿Es Nodo\nFinal?}
    V -->|No| W[Verificar si hay mensajes\nsiguientes en la secuencia]
    V -->|Sí| X[Verificar si se requiere\ntransferir a agente]
    
    W --> Y{¿Hay más\nmensajes?}
    Y -->|Sí| O
    Y -->|No| AA[Esperar próxima\ninteracción del usuario]
    
    X --> AB{¿Transferir\na agente?}
    AB -->|Sí| AC[Buscar agente disponible\nen el departamento]
    AB -->|No| AD[Finalizar conversación]
    
    AC --> AE[Notificar al agente]
    AE --> AF[Actualizar estado\na 'transferido']
    
    AD --> AG[Actualizar estado\na 'finalizado']
    AF --> AA
    AG --> AA