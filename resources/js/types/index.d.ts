export interface Cliente {
  id: string
  tipoDocumento: string
  numeroDocumento: string
  razonSocial: string
  direccion: string
  telefono: string
  email: string
  createdAt: string
  updatedAt: string
}

export type EstadoEdificio = 'activo' | 'inactivo'

export interface Edificio {
  id: string
  nombre: string
  ruc: string | null
  direccion: string
  ciudad: string
  telefono: string | null
  correo: string | null
  responsable: string | null
  estado: EstadoEdificio
  createdAt: string
  updatedAt: string
}

export interface EdificioFormData {
  nombre: string
  ruc: string
  direccion: string
  ciudad: string
  telefono: string
  correo: string
  responsable: string
}

export type EstadoEstructura = 'activo' | 'inactivo'
export type EstadoOperativoAnexo = 'disponible' | 'asignado' | 'inactivo'

export interface PisoEstructura {
  id: string
  torreId: string
  numero: string
  nombre: string | null
  orden: number
  estado: EstadoEstructura
  departamentos: number
}

export interface TorreEstructura {
  id: string
  codigo: string
  nombre: string
  descripcion: string | null
  esPredeterminada: boolean
  estado: EstadoEstructura
  pisos: PisoEstructura[]
}

export interface AnexoEstructura {
  id: string
  torreId: string | null
  torre: string | null
  codigo: string
  ubicacion: string | null
  estado: EstadoEstructura
  estadoOperativo: EstadoOperativoAnexo
  departamento: { id: string, codigo: string } | null
}

export interface EstructuraEdificio {
  torres: TorreEstructura[]
  parqueaderos: AnexoEstructura[]
  bodegas: AnexoEstructura[]
  resumen: {
    torres: number
    pisos: number
    departamentos: number
    parqueaderosDisponibles: number
    bodegasDisponibles: number
  }
}

export interface Departamento {
  id: string
  edificioId: string
  edificio: string
  torreId: string
  torre: string
  pisoId: string
  piso: string
  codigo: string
  nombre: string
  alicuota: string
  estado: EstadoEstructura
  observaciones: string | null
  parqueaderos: Array<{ id: string, codigo: string }>
  bodegas: Array<{ id: string, codigo: string }>
}

export interface DepartamentoFormData {
  edificio_id: string
  piso_id: string
  codigo: string
  nombre: string
  alicuota: string
  observaciones: string
  parqueaderos: string[]
  bodegas: string[]
}

export interface EdificioEstructuraOption {
  id: string
  nombre: string
  estado: EstadoEdificio
  torres: Array<{
    id: string
    nombre: string
    estado: EstadoEstructura
    pisos: Array<{ id: string, numero: string, nombre: string | null, estado: EstadoEstructura }>
  }>
  parqueaderos: Array<{ id: string, codigo: string, departamentoId: string | null }>
  bodegas: Array<{ id: string, codigo: string, departamentoId: string | null }>
}

export type TipoPersona = 'persona_natural' | 'persona_juridica'
export type TipoIdentificacion = 'cedula' | 'ruc' | 'pasaporte' | 'otro'
export type EstadoPropietario = 'activo' | 'inactivo'
export type EstadoTitularidad = 'activa' | 'finalizada'

export interface PropiedadDepartamento {
  id: string
  edificioId: string
  edificio: string
  departamentoId: string
  departamento: string
  torre: string
  piso: string
  porcentaje: string
  fechaInicio: string
  fechaFin: string | null
  estado: EstadoTitularidad
  observaciones: string | null
}

export interface Propietario {
  id: string
  tipoPersona: TipoPersona
  nombres: string | null
  apellidos: string | null
  razonSocial: string | null
  nombre: string
  tipoIdentificacion: TipoIdentificacion
  identificacion: string
  telefono: string | null
  celular: string | null
  correo: string | null
  direccion: string | null
  estado: EstadoPropietario
  observaciones: string | null
  propiedadesActualesCount: number
  puedeGestionar: boolean | null
  propiedadesActuales?: PropiedadDepartamento[]
  historialPropiedades?: PropiedadDepartamento[]
  createdAt: string | null
  updatedAt: string | null
}

export interface PropietarioFormData {
  edificio_id: string
  tipo_persona: TipoPersona
  nombres: string
  apellidos: string
  razon_social: string
  tipo_identificacion: TipoIdentificacion
  identificacion: string
  telefono: string
  celular: string
  correo: string
  direccion: string
  observaciones: string
}

export interface PropietarioOption {
  id: string
  nombre: string
  identificacion: string
}

export interface TitularidadDepartamento {
  id: string
  propietarioId: string
  nombre: string
  identificacion: string
  porcentaje: string
  fechaInicio: string
  fechaFin: string | null
  estado: EstadoTitularidad
  observaciones: string | null
}

export interface DepartamentoPropiedad {
  actuales: TitularidadDepartamento[]
  historial: TitularidadDepartamento[]
  opciones: PropietarioOption[]
  participacionActual: string
}

export type TipoConceptoCobro = 'ordinario' | 'extraordinario' | 'consumo' | 'multa' | 'interes' | 'otro'
export type PeriodicidadCobro = 'mensual' | 'trimestral' | 'semestral' | 'anual' | 'unico' | 'manual'
export type FormaCalculoCobro = 'valor_fijo' | 'por_alicuota' | 'porcentaje' | 'por_consumo' | 'manual'
export type EstadoConceptoCobro = 'activo' | 'inactivo'
export type AlcanceTarifa = 'todo_el_edificio' | 'departamentos_especificos'
export type EstadoTarifa = 'vigente' | 'finalizada' | 'programada'
export type BaseCalculoInteres = 'saldo_vencido' | 'capital_vencido' | 'saldo_total'

export interface DepartamentoTarifa {
  id: string
  codigo: string
  nombre: string
}

export interface TarifaConcepto {
  id: string
  valor: string | null
  porcentaje: string | null
  montoTotal: string | null
  numeroCuotas: number | null
  unidad: string | null
  baseCalculo: BaseCalculoInteres | null
  fechaInicio: string
  fechaFin: string | null
  alcance: AlcanceTarifa
  observacion: string | null
  estado: EstadoTarifa
  departamentos: DepartamentoTarifa[]
}

export interface ConceptoCobro {
  id: string
  edificioId: string
  edificio: string | null
  codigo: string
  nombre: string
  descripcion: string | null
  tipo: TipoConceptoCobro
  periodicidad: PeriodicidadCobro
  formaCalculo: FormaCalculoCobro
  estado: EstadoConceptoCobro
  tarifaVigente: TarifaConcepto | null
  tarifas?: TarifaConcepto[]
  departamentosDisponibles?: DepartamentoTarifa[]
  createdAt: string | null
  updatedAt: string | null
}

export interface ConceptoCobroFormData {
  edificio_id: string
  codigo: string
  nombre: string
  descripcion: string
  tipo: TipoConceptoCobro
  periodicidad: PeriodicidadCobro
  forma_calculo: FormaCalculoCobro
  estado: EstadoConceptoCobro
}

export interface TarifaConceptoFormData {
  valor: string
  porcentaje: string
  monto_total: string
  numero_cuotas: string
  unidad: string
  base_calculo: BaseCalculoInteres | ''
  fecha_inicio: string
  fecha_fin: string
  alcance: AlcanceTarifa
  departamentos: string[]
  observacion: string
}

export type EstadoCargo = 'pendiente' | 'parcial' | 'pagado' | 'anulado'
export type OrigenCargo = 'automatico' | 'manual' | 'importado' | 'ajuste'

export interface Cargo {
  id: string
  edificioId: string
  departamentoId: string
  departamento: string | null
  conceptoId: string
  concepto: string | null
  codigoConcepto: string | null
  tarifaId: string | null
  periodo: string
  fechaEmision: string
  fechaVencimiento: string
  descripcion: string
  valorOriginal: string
  saldo: string
  estado: EstadoCargo
  origen: OrigenCargo
  loteId: string | null
  referenciaGeneracion: string | null
  anuladoAt: string | null
  motivoAnulacion: string | null
  snapshot: Record<string, unknown> | null
  aplicacionesPago?: Array<{ pagoId: string, numeroPago: string | null, fechaPago: string | null, valorAplicado: string, estadoPago: EstadoPago | undefined }> | null
}

export interface CargoOption {
  id: string
  edificioId: string
  codigo: string
  nombre: string
  estado?: EstadoConceptoCobro
}

export interface CargoPreviewItem {
  departamentoId: string
  departamento: string
  conceptoId: string
  concepto: string
  formaCalculo: FormaCalculoCobro
  tarifaId: string
  tarifa: string | null
  base: string | null
  valor: string
}

export interface CargoPreview {
  periodo: string
  cargos: CargoPreviewItem[]
  cantidadCargos: number
  cantidadOmitidos: number
  totalValor: string
  advertencias: Array<{ codigo: string, mensaje: string, departamento?: string, concepto?: string }>
}

export type EstadoPago = 'registrado' | 'anulado'
export type FormaPago = 'efectivo' | 'transferencia' | 'deposito' | 'tarjeta' | 'cheque' | 'otro'

export interface PagoAplicacion {
  id: string
  cargoId: string
  concepto: string | null
  periodo: string | null
  valorAplicado: string
  createdAt: string | null
}

export interface Pago {
  id: string
  edificioId: string
  departamentoId: string
  departamento: string | null
  propietarioId: string | null
  propietario: string
  numero: string
  fechaPago: string
  valorRecibido: string
  valorAplicado: string
  valorAplicadoHistorico: string
  saldoFavor: string
  formaPago: FormaPago
  referencia: string | null
  observacion: string | null
  estado: EstadoPago
  origen: string
  registradoPor: string | null
  anuladoAt: string | null
  motivoAnulacion: string | null
  saldoAnterior: string | null
  saldoPosterior: string | null
  propietarios?: Array<{ id: string, nombre: string, identificacion: string, porcentaje: string }> | null
  aplicaciones?: PagoAplicacion[] | null
}

export interface PagoPreview {
  departamento: { id: string, codigo: string, nombre: string }
  propietarios: Array<{ id: string, nombre: string, identificacion: string, porcentaje: string }>
  saldoPendiente: string
  saldoFavorActual: string
  saldoNetoActual: string
  valorRecibido: string
  valorAplicado: string
  saldoFavorNuevo: string
  saldoDespues: string
  cargos: Array<{ cargoId: string, concepto: string, periodo: string, fechaVencimiento: string, saldoAnterior: string, valorAplicado: string, saldoPosterior: string }>
}

export interface CarteraItem {
  edificioId: string
  edificio: string | null
  departamentoId: string
  departamento: string
  propietarios: Array<{ id: string, nombre: string, identificacion: string, porcentaje: string }>
  cargosTotales: string
  pagosAplicados: string
  saldoPendiente: string
  saldoFavor: string
  saldoNeto: string
}

export interface CarteraResumen {
  totalCartera: string
  pagosAplicados: string
  saldoPendiente: string
  saldoVencido: string
  saldoNoVencido: string
  saldoFavor: string
  saldoNeto: string
  departamentosAlDia: number
  departamentosConDeuda: number
  morosidadPorcentaje: string
}

export interface CarteraDetalleItem extends CarteraItem {
  torre: string | null
  piso: string | null
  nombreDepartamento: string
  estadoDepartamento: EstadoEstructura
  saldoVencido: string
  saldoNoVencido: string
  saldoNetoDeudor: string
  saldoNetoAcreedor: string
  fechaVencimientoMasAntigua: string | null
  diasAtraso: number
  antiguedad: 'al_dia' | '1_a_30' | '31_a_60' | '61_a_90' | 'mas_de_90'
  estado: 'al_dia' | 'moroso' | 'saldo_a_favor'
  ultimoPago: string | null
  ultimaGestionCobro: null
}

export interface EstadoCuentaMovimiento {
  id: string
  fecha: string
  tipo: 'cargo' | 'pago' | 'anulacion_cargo' | 'anulacion_pago'
  referencia: string
  debito: string
  credito: string
  saldoAcumulado: string
  cargoId?: string
  pagoId?: string
  concepto?: string | null
  periodo?: string | null
  aplicaciones?: Array<{ cargoId: string, concepto: string | null, periodo: string | null, montoAplicado: string }>
}

export interface EstadoCuenta {
  edificio: { id: string, nombre: string | null }
  departamento: { id: string, codigo: string, nombre: string, estado: EstadoEstructura }
  periodo: { desde: string, hasta: string }
  propietarios: Array<{ id: string, nombre: string, identificacion: string, porcentaje: string }>
  saldoInicial: { neto: string, deudor: string, acreedor: string }
  saldoFinal: { neto: string, deudor: string, acreedor: string }
  resumen: { debitos: string, creditos: string }
  movimientos: EstadoCuentaMovimiento[]
}
