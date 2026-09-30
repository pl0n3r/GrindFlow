# =============================================================================
# Workers de medios — imagen de produccion
# =============================================================================
# Aqui vive FFmpeg. Es la razon por la que este servicio no cabe en una funcion
# serverless: transcodificar un video de media hora excede cualquier limite de
# ejecucion de ese modelo.

FROM python:3.11-slim

# ffmpeg para video; las libs de imagen las necesita Pillow para JPEG y WEBP.
# --no-install-recommends evita arrastrar medio escritorio como dependencia.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ffmpeg \
      libjpeg62-turbo \
      libwebp7 \
      fonts-dejavu-core \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Las dependencias antes que el codigo: cambiar un worker no reinstala todo.
COPY workers/requirements.lock ./requirements.lock
RUN pip install --no-cache-dir --require-hashes --only-binary=:all: -r requirements.lock

COPY workers/ ./

# Usuario sin privilegios. Los temporales van a /tmp, que sigue siendo escribible.
RUN useradd --system --create-home --uid 1001 worker \
 && chown -R worker:worker /app
USER worker

ENV PYTHONUNBUFFERED=1

# Sin argumentos consume todos los tipos de trabajo. Para especializar un
# contenedor: command: ["python", "main.py", "sanitize_exif"]
CMD ["python", "main.py"]
