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
