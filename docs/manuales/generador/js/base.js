// Piezas comunes de los manuales (docx-js): estilos, portada, índice, encabezado/pie y bloques de contenido.
const fs = require('fs');
const path = require('path');
const {
  AlignmentType, BorderStyle, Document, Footer, Header, HeadingLevel, ImageRun, LevelFormat, PageBreak, PageNumber, Paragraph,
  ShadingType, Table, TableCell, TableOfContents, TableRow, TextRun, WidthType, Packer, TabStopType
} = require('docx');

const GUINDA = '7A1F3D';
const GRIS = '595959';
const IMG = path.join(__dirname, '..', 'imgj');
const LOGO = path.join(__dirname, '..', '..', '..', '..', 'frontend', 'src', 'assets', 'images', 'logo-guadalupe.png');
const ANCHO = 9360; // 6.5" en DXA

const medidas = Object.fromEntries(
  fs.readFileSync(path.join(IMG, 'medidas.txt'), 'utf8').trim().split('\n')
    .map((l) => l.trim().split(/\s+/)).map(([n, w, h]) => [n, [Number(w), Number(h)]])
);

/** Texto con **negritas** y `código`. */
function runs(texto, base = {}) {
  return texto.split(/(\*\*[^*]+\*\*|`[^`]+`)/).filter(Boolean).map((t) =>
    t.startsWith('**') ? new TextRun({ ...base, text: t.slice(2, -2), bold: true })
      : t.startsWith('`') ? new TextRun({ ...base, text: t.slice(1, -1), font: 'Consolas', size: 19, color: '7A1F3D' })
        : new TextRun({ ...base, text: t }));
}

let listas = 0;
let figura = 0;

const B = {
  h1: (t) => new Paragraph({ heading: HeadingLevel.HEADING_1, pageBreakBefore: true, children: [new TextRun(t)] }),
  h1SinSalto: (t) => new Paragraph({ heading: HeadingLevel.HEADING_1, children: [new TextRun(t)] }),
  h2: (t) => new Paragraph({ heading: HeadingLevel.HEADING_2, children: [new TextRun(t)] }),
  h3: (t) => new Paragraph({ heading: HeadingLevel.HEADING_3, children: [new TextRun(t)] }),
  p: (t) => new Paragraph({ spacing: { after: 120 }, children: runs(t) }),
  /** Viñetas. */
  l: (items) => items.map((t) => new Paragraph({ numbering: { reference: 'vinetas', level: 0 }, spacing: { after: 60 }, children: runs(t) })),
  /** Pasos numerados (cada lista empieza en 1). */
  n: (items) => {
    const instancia = ++listas;
    return items.map((t) => new Paragraph({ numbering: { reference: 'pasos', level: 0, instance: instancia }, spacing: { after: 60 }, children: runs(t) }));
  },
  /** Recuadro de nota o advertencia. */
  nota: (t, titulo = 'Nota') => new Paragraph({
    spacing: { before: 120, after: 160 },
    shading: { type: ShadingType.CLEAR, fill: 'F6EDF0', color: 'auto' },
    border: { left: { style: BorderStyle.SINGLE, size: 18, color: GUINDA, space: 8 } },
    indent: { left: 120, right: 120 },
    children: [new TextRun({ text: `${titulo}: `, bold: true, color: GUINDA }), ...runs(t)]
  }),
  /** Captura de pantalla con su pie de figura. */
  img: (nombre, pie, maxAlto = 640) => {
    const [w, h] = medidas[nombre];
    let ancho = 600;
    let alto = Math.round((h * ancho) / w);
    if (alto > maxAlto) {
      alto = maxAlto;
      ancho = Math.round((w * alto) / h);
    }
    figura++;
    return [
      new Paragraph({
        alignment: AlignmentType.CENTER, spacing: { before: 120, after: 60 }, keepNext: true,
        children: [new ImageRun({ type: 'jpg', data: fs.readFileSync(path.join(IMG, `${nombre}.jpg`)), transformation: { width: ancho, height: alto },
          altText: { title: pie, description: pie, name: nombre } })]
      }),
      new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 200 }, children: [new TextRun({ text: `Figura ${figura}. `, bold: true, size: 18, color: GRIS }), new TextRun({ text: pie, italics: true, size: 18, color: GRIS })] })
    ];
  },
  /** Tabla con encabezado guinda. anchos en DXA (suman ANCHO). */
  tabla: (encabezados, filas, anchos) => {
    const borde = { style: BorderStyle.SINGLE, size: 4, color: 'D9D9D9' };
    const bordes = { top: borde, bottom: borde, left: borde, right: borde };
    const celda = (t, i, enc) => new TableCell({
      width: { size: anchos[i], type: WidthType.DXA }, borders: bordes, margins: { top: 60, bottom: 60, left: 100, right: 100 },
      shading: enc ? { type: ShadingType.CLEAR, fill: GUINDA, color: 'auto' } : undefined,
      children: [new Paragraph({ children: runs(String(t), enc ? { bold: true, color: 'FFFFFF', size: 19 } : { size: 19 }) })]
    });
    return new Table({
      width: { size: ANCHO, type: WidthType.DXA }, columnWidths: anchos,
      rows: [new TableRow({ tableHeader: true, children: encabezados.map((t, i) => celda(t, i, true)) }), ...filas.map((f) => new TableRow({ children: f.map((t, i) => celda(t, i, false)) }))]
    });
  },
  espacio: () => new Paragraph({ children: [] }),
  /** Bloque de código o comandos (una línea por párrafo). */
  codigo: (texto) => texto.split('\n').map((linea, i, todas) => new Paragraph({
    spacing: { before: i === 0 ? 80 : 0, after: i === todas.length - 1 ? 160 : 0 },
    shading: { type: ShadingType.CLEAR, fill: 'F2F2F2', color: 'auto' },
    indent: { left: 120, right: 120 },
    children: [new TextRun({ text: linea || ' ', font: 'Consolas', size: 18 })]
  }))
};

function portada(titulo, subtitulo, version) {
  return [
    new Paragraph({ spacing: { before: 1200 }, alignment: AlignmentType.CENTER, children: [new ImageRun({ type: 'png', data: fs.readFileSync(LOGO), transformation: { width: 180, height: 180 * logoProporcion() },
      altText: { title: 'Ayuntamiento de Guadalupe', description: 'Logotipo del Ayuntamiento de Guadalupe 2024-2027', name: 'logo' } })] }),
    new Paragraph({ spacing: { before: 600 }, alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'SIEG', bold: true, size: 72, color: GUINDA })] }),
    new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'Sistema Integral de Egresos Municipales', size: 28, color: GRIS })] }),
    new Paragraph({ spacing: { before: 800 }, alignment: AlignmentType.CENTER, children: [new TextRun({ text: titulo, bold: true, size: 48 })] }),
    new Paragraph({ spacing: { before: 120 }, alignment: AlignmentType.CENTER, children: [new TextRun({ text: subtitulo, size: 24, color: GRIS })] }),
    new Paragraph({ spacing: { before: 1600 }, alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'H. Ayuntamiento de Guadalupe, Zacatecas · Administración 2024–2027', size: 22 })] }),
    new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ text: version, size: 20, color: GRIS })] }),
    new Paragraph({ children: [new PageBreak()] }),
    new Paragraph({ heading: HeadingLevel.HEADING_1, children: [new TextRun('Contenido')] }),
    new TableOfContents('Contenido', { hyperlink: true, headingStyleRange: '1-2' }),
    new Paragraph({ spacing: { before: 200 }, children: [new TextRun({ text: 'Si el índice aparece vacío, en Word haz clic derecho sobre él y elige «Actualizar campo».', italics: true, size: 18, color: GRIS })] })
  ];
}

function logoProporcion() {
  // PNG: ancho y alto en los bytes 16-23.
  const b = fs.readFileSync(LOGO);
  return b.readUInt32BE(20) / b.readUInt32BE(16);
}

function documento(titulo, contenido) {
  return new Document({
    creator: 'SIEG', title: titulo, description: titulo,
    // Word ofrece actualizar el índice al abrir el documento.
    features: { updateFields: !process.env.SIN_ACTUALIZAR },
    styles: {
      default: { document: { run: { font: 'Calibri', size: 22 } } },
      paragraphStyles: [
        { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 36, bold: true, color: GUINDA, font: 'Calibri' }, paragraph: { spacing: { before: 240, after: 200 }, outlineLevel: 0 } },
        { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 28, bold: true, color: '333333', font: 'Calibri' }, paragraph: { spacing: { before: 280, after: 120 }, outlineLevel: 1, keepNext: true } },
        { id: 'Heading3', name: 'Heading 3', basedOn: 'Normal', next: 'Normal', quickFormat: true, run: { size: 24, bold: true, color: GUINDA, font: 'Calibri' }, paragraph: { spacing: { before: 200, after: 80 }, outlineLevel: 2, keepNext: true } }
      ]
    },
    numbering: {
      config: [
        { reference: 'vinetas', levels: [{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 540, hanging: 270 } } } }] },
        { reference: 'pasos', levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT, style: { paragraph: { indent: { left: 540, hanging: 300 } } } }] }
      ]
    },
    sections: [{
      properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 1440, right: 1440, bottom: 1440, left: 1440 } }, titlePage: true },
      headers: {
        default: new Header({ children: [new Paragraph({ border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: GUINDA, space: 4 } },
          tabStops: [{ type: TabStopType.RIGHT, position: ANCHO }],
          children: [new TextRun({ text: 'SIEG · ' + titulo, size: 18, color: GRIS }), new TextRun({ text: '\tAyuntamiento de Guadalupe, Zac.', size: 18, color: GRIS })] })] })
      },
      footers: {
        default: new Footer({ children: [new Paragraph({ alignment: AlignmentType.CENTER, children: [new TextRun({ children: ['Página ', PageNumber.CURRENT, ' de ', PageNumber.TOTAL_PAGES], size: 18, color: GRIS })] })] })
      },
      children: contenido.flat(2)
    }]
  });
}

async function guardar(doc, archivo) {
  fs.mkdirSync(path.dirname(archivo), { recursive: true });
  fs.writeFileSync(archivo, await Packer.toBuffer(doc));
  console.log('✔', archivo, Math.round(fs.statSync(archivo).size / 1024), 'KB');
}

module.exports = { B, portada, documento, guardar, ANCHO, reiniciar: () => { figura = 0; listas = 0; } };
