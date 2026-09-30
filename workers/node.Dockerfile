# =============================================================================
# Workers de Node — imagen de produccion
# =============================================================================
# Sirve a los dos workers de Node —ingesta y publicacion— que se distinguen por
# el `command` del servicio. Comparten imagen porque comparten todo lo que
# importa: el cifrado AES-256-GCM de `src/lib/crypto/secrets.ts` y los tipos del
# esquema.
#
# En Node y no en Python, a diferencia de los workers de medios: una segunda
# implementacion de ese formato criptografico en otro lenguaje acabaria
# divergiendo en silencio. Ademas esto es E/S pura y no necesita FFmpeg, asi que
# la imagen es mucho mas ligera que la de medios.

FROM node:22-alpine
WORKDIR /app

# Las dependencias antes que el codigo: cambiar el worker no reinstala todo.
COPY package.json package-lock.json ./
# Se instalan tambien las de desarrollo porque `tsx` ejecuta el TypeScript
# directamente. Compilar a JavaScript aparte anadiria un paso de build y un
# directorio de salida para ahorrar unos megas en una imagen que no se
# distribuye.
RUN npm ci --ignore-scripts

COPY tsconfig.json tsconfig.workers.json ./
COPY src ./src

ENV NODE_ENV=production
ENV NODE_OPTIONS=--max-old-space-size=512

USER node

# `tsconfig.workers.json` es lo que hace posible ejecutar fuera de Next: sustituye
# `server-only`, que lanza una excepcion al cargarse fuera de un React Server
# Component. El tsconfig principal no lo toca, asi que la proteccion del bundle
# del navegador sigue intacta.
# Por defecto, ingesta. El servicio `publish` de docker-compose lo sustituye.
CMD ["./node_modules/.bin/tsx", "--tsconfig", "tsconfig.workers.json", "src/workers/ingest/index.ts"]
