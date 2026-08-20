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
