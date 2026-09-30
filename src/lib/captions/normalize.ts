/**
 * Normalizacion de texto para la deteccion de terminos prohibidos.
 *
 * Quien quiere colar una palabra vetada no la escribe tal cual: la escribe
 * `t.e.e.n`, `t3en`, `téén` o `t e e n`. Comparar contra el texto crudo solo
 * atrapa al que no lo intenta.
 *
 * El problema de normalizar agresivamente es el contrario: si se eliminan todos
 * los separadores y luego se busca por subcadena, "cl ass" pasa a contener
 * "ass" y se bloquea un texto legitimo. Un filtro que da falsos positivos se
 * desactiva a la semana.
 *
 * La solucion es no buscar por subcadena sino construir, para cada termino, un
 * patron que tolere separadores ENTRE sus letras pero exija frontera de palabra
 * a los lados. Asi `t.e.e.n` coincide y `class` no.
 */

/** Sustituciones leet mas frecuentes. */
const LEET: Record<string, string> = {
  '0': 'o',
  '1': 'i',
  '3': 'e',
  '4': 'a',
  '5': 's',
  '7': 't',
  '8': 'b',
  $: 's',
  '@': 'a',
  '!': 'i',
  '|': 'i',
};

/**
 * Caracteres de ancho cero y marcas invisibles.
 *
 * Se usan justo para esto: partir una palabra vetada por dentro de forma que el
 * filtro no la vea y el lector si. Su presencia en un caption generado es en si
 * misma una senal de manipulacion, asi que ademas de limpiarlos se reportan.
 */
const INVISIBLE = /[\u00ad\u200b-\u200f\u202a-\u202e\u2060-\u2064\ufeff]/g;

export function hasInvisibleCharacters(text: string): boolean {
  INVISIBLE.lastIndex = 0;
  return INVISIBLE.test(text);
}

export function stripInvisibleCharacters(text: string): string {
  return text.replace(INVISIBLE, '');
}

/**
 * Deja el texto en minusculas, sin acentos y con los digitos leet resueltos.
 * Conserva los separadores: de eliminarlos se encarga el patron de cada termino.
 */
export function normalizeForMatching(text: string): string {
  return stripInvisibleCharacters(text)
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[0134578$@!|]/g, (char) => LEET[char] ?? char);
}

/** Escapa lo que tenga significado dentro de una expresion regular. */
function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Construye el patron de un termino prohibido.
 *
 * Entre cada letra admite hasta tres caracteres que no sean alfanumericos
 * (puntos, guiones, espacios, asteriscos). A los lados exige que no haya letra
 * ni digito, que es lo que evita el falso positivo de "class" conteniendo "ass".
 *
 * El tope de tres separadores es deliberado: sin limite, un texto largo lleno de
 * puntuacion podria hacer coincidir letras que estan a parrafos de distancia.
 */
export function buildTermPattern(term: string): RegExp {
  const normalized = normalizeForMatching(term).trim();
  const body = normalized
    .split('')
    .filter((char) => /[a-z0-9]/.test(char))
    .map(escapeRegExp)
    .join('[^a-z0-9]{0,3}');

  return new RegExp(`(?<![a-z0-9])${body}(?![a-z0-9])`, 'i');
}

/** Devuelve los terminos de la lista que aparecen en el texto. */
export function findTerms(text: string, terms: readonly string[]): string[] {
  const normalized = normalizeForMatching(text);
  return terms.filter((term) => buildTermPattern(term).test(normalized));
}
