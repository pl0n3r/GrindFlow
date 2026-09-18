# Despliegue en VPS propio

> **Documento del legado TypeScript/Next.js.** El despliegue Laravel actual en
> Hostinger se documenta en `docs/DEPLOY-HOSTINGER.md`. Este archivo se conserva
> durante la migracion para referencia funcional del stack anterior.

El destino es un servidor propio (Hetzner u otro) con contenedores. Se descarto
Vercel por dos razones concretas: sus terminos prohiben contenido adulto, y
FFmpeg sobre video largo no cabe en los limites de ejecucion de una funcion
serverless.

## Los dos servicios

| Servicio | Imagen | Que hace |
|---|---|---|
| `web` | `Dockerfile` | Panel Next.js, escucha en el 3000 |
| `worker` | `workers/Dockerfile` | Pipeline de medios con FFmpeg |

Van separados porque tienen formas de carga opuestas: el panel atiende muchas
peticiones cortas y el worker pocas tareas largas y golosas de CPU. Juntos, una
transcodificacion dejaria el panel sin respuesta, y ademas no se podrian escalar
por separado.

```bash
docker compose up -d --scale worker=4   # mas capacidad de proceso, un solo panel
```

Escalar workers no necesita coordinacion: `app.claim_jobs` usa
`FOR UPDATE SKIP LOCKED`, asi que cada uno toma trabajos distintos sin hablar con
los demas.

## Puesta en marcha

```bash
git clone <repo> && cd grindflow
cp .env.example .env          # compose lee .env, no .env.local

# Genera los tres secretos:
openssl rand -hex 32          # UPLOAD_LINK_SECRET
openssl rand -hex 32          # ENCRYPTION_MASTER_KEY  (exactamente 64 caracteres)
openssl rand -hex 16          # CLICK_IP_SALT

docker compose up -d --build
docker compose logs -f
```

La base de datos sigue en Supabase, que aporta Auth, PostgREST y las copias de
seguridad. Para autoalojarla, usa el compose oficial de Supabase en su propia
pila y apunta aqui `NEXT_PUBLIC_SUPABASE_URL` y `DATABASE_URL`.

## Proxy inverso: no es opcional

`docker-compose.yml` no incluye uno para no imponer una eleccion, pero hace falta
delante, y por algo mas que el TLS.

El limitador de tasa identifica al visitante por `X-Forwarded-For`, una cabecera
que **el cliente puede falsificar**. Si el contenedor `web` queda accesible desde
fuera, cualquiera envia una cabecera distinta en cada peticion y el limite deja
de existir. El proxy tiene que reescribirla y `web` no debe exponerse
directamente: publica solo el 80 y el 443 del proxy, y deja el 3000 en la red
interna de Docker.

Con Caddy basta un `Caddyfile` de tres lineas:

```
panel.tudominio.com {
    reverse_proxy web:3000
}
```

Caddy fija `X-Forwarded-For` con la IP real de la conexion y descarta la que
venga del cliente.

## Enlaces cortos y listas de bloqueo

El acortador vive en la misma aplicacion, en `/l/[slug]`. Conviene apuntarle un
dominio distinto al del panel: los enlaces NSFW acaban en listas de bloqueo de
Telegram, X y los filtros corporativos, y no quieres que arrastren al panel.
Ambos dominios pueden apuntar al mismo contenedor.

## Copias de seguridad

- **PostgreSQL**: lo cubre Supabase. Si lo autoalojas, es tuyo.
- **R2**: versionado de objetos activado en el bucket.
- **`ENCRYPTION_MASTER_KEY`**: guardala fuera del servidor. Sin ella, las
  credenciales de las plataformas son irrecuperables aunque tengas el volcado
  completo de la base, y hay que reconectar cada cuenta a mano.

## Actualizar

```bash
git pull
docker compose up -d --build    # reconstruye e intercambia los contenedores
```

Las migraciones nuevas se aplican aparte, antes de levantar la version nueva:

```bash
npx supabase db push
```
