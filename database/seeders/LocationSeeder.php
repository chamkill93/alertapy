<?php

namespace Database\Seeders;

use App\Models\GeocodingCache;
use App\Models\Location;
use App\Models\LocationAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $item) {
            $normalizedName = $this->normalize($item['name']);
            $normalizedQuery = $this->normalize($item['query']);
            $cached = GeocodingCache::query()->where('normalized_query', $normalizedQuery)->first();
            $location = Location::query()
                ->whereIn('normalized_name', [$normalizedName, $normalizedQuery])
                ->first() ?? new Location;

            $location->fill([
                'type' => $item['type'],
                'name' => $item['name'],
                'normalized_name' => $normalizedName,
                'geocoding_query' => $item['query'],
                'normalized_query' => $normalizedQuery,
                'city' => $item['city'],
                'department' => $item['department'],
                'accuracy' => $item['accuracy'],
                'enabled' => true,
                'latitude' => $item['latitude'] ?? $location->latitude ?? $cached?->latitude,
                'longitude' => $item['longitude'] ?? $location->longitude ?? $cached?->longitude,
            ])->save();

            $aliases = array_values(array_unique([$item['name'], ...$item['aliases']]));

            foreach ($aliases as $index => $alias) {
                LocationAlias::query()->updateOrCreate(
                    ['normalized_alias' => $this->normalize($alias)],
                    [
                        'location_id' => $location->id,
                        'alias' => $alias,
                        'priority' => $index === 0 ? 50 : 100 + $index,
                    ],
                );
            }
        }
    }

    /** @return array<int, array{type: string, name: string, query: string, city: ?string, department: ?string, accuracy: string, aliases: array<int, string>, latitude?: float, longitude?: float}> */
    private function catalog(): array
    {
        return [
            ['type' => 'park', 'name' => 'Parque Nacional Médanos del Chaco', 'query' => 'Parque Nacional Médanos del Chaco', 'city' => null, 'department' => 'Boquerón', 'accuracy' => 'approximate', 'aliases' => ['Médanos del Chaco'], 'latitude' => -20.3702748, 'longitude' => -61.6179977],
            ['type' => 'border_crossing', 'name' => 'Puente Internacional de la Amistad', 'query' => 'Puente Internacional de la Amistad, Paraguay', 'city' => 'Ciudad del Este', 'department' => 'Alto Paraná', 'accuracy' => 'approximate', 'aliases' => ['Puente de la Amistad', 'Ciudad del Este–Foz de Iguazú', 'Ciudad del Este-Foz de Iguazú']],
            ['type' => 'border_crossing', 'name' => 'Puente Internacional de la Integración', 'query' => 'Puente de la Integración, Presidente Franco, Paraguay', 'city' => 'Presidente Franco', 'department' => 'Alto Paraná', 'accuracy' => 'approximate', 'aliases' => ['Puente de la Integración', 'Presidente Franco–Foz de Iguazú', 'Presidente Franco-Foz de Iguazú']],
            ['type' => 'border_crossing', 'name' => 'Puente Internacional San Roque González de Santa Cruz', 'query' => 'Puente San Roque González de Santa Cruz, Encarnación, Paraguay', 'city' => 'Encarnación', 'department' => 'Itapúa', 'accuracy' => 'approximate', 'aliases' => ['Puente San Roque González', 'Encarnación–Posadas', 'Encarnación-Posadas']],
            ['type' => 'border_crossing', 'name' => 'Puerto José Falcón', 'query' => 'Paso fronterizo Puerto Falcón, Paraguay', 'city' => 'José Falcón', 'department' => 'Presidente Hayes', 'accuracy' => 'approximate', 'aliases' => ['Puerto Falcón', 'Falcón–Clorinda', 'Falcón-Clorinda', 'Puerto Falcón–Clorinda']],
            ['type' => 'border_crossing', 'name' => 'Paso Salto del Guairá–Mundo Novo', 'query' => 'Paso fronterizo Salto del Guairá, Paraguay', 'city' => 'Salto del Guairá', 'department' => 'Canindeyú', 'accuracy' => 'approximate', 'aliases' => ['Salto del Guairá–Mundo Novo', 'Salto del Guairá-Mundo Novo']],
            ['type' => 'border_crossing', 'name' => 'Paso Pedro Juan Caballero–Ponta Porã', 'query' => 'Paso fronterizo Pedro Juan Caballero, Paraguay', 'city' => 'Pedro Juan Caballero', 'department' => 'Amambay', 'accuracy' => 'approximate', 'aliases' => ['Pedro Juan Caballero–Ponta Porã', 'Pedro Juan Caballero-Ponta Porã', 'PJC–Ponta Porã']],
            ['type' => 'airport', 'name' => 'Aeropuerto Internacional Silvio Pettirossi', 'query' => 'Aeropuerto Internacional Silvio Pettirossi, Paraguay', 'city' => 'Luque', 'department' => 'Central', 'accuracy' => 'approximate', 'aliases' => ['Silvio Pettirossi', 'AISP']],
            ['type' => 'airport', 'name' => 'Aeropuerto Internacional Guaraní', 'query' => 'Aeropuerto Internacional Guaraní, Minga Guazú, Paraguay', 'city' => 'Minga Guazú', 'department' => 'Alto Paraná', 'accuracy' => 'approximate', 'aliases' => ['Aeropuerto Guaraní', 'AIG']],
            ['type' => 'airport', 'name' => 'Aeropuerto Internacional Teniente Amín Ayub González', 'query' => 'Aeropuerto Teniente Amín Ayub González, Encarnación, Paraguay', 'city' => 'Encarnación', 'department' => 'Itapúa', 'accuracy' => 'approximate', 'aliases' => ['Aeropuerto Teniente Amín Ayub González', 'Aeropuerto de Encarnación']],
            ['type' => 'neighborhood', 'name' => 'Mburuvicha Róga', 'query' => 'Mburuvicha Róga, Asunción', 'city' => 'Asunción', 'department' => 'Capital', 'accuracy' => 'neighborhood', 'aliases' => ['Mburuvicha Roga']],
            ['type' => 'city', 'name' => 'Mariano Roque Alonso', 'query' => 'Mariano Roque Alonso', 'city' => 'Mariano Roque Alonso', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => ['MRA']],
            ['type' => 'city', 'name' => 'Fernando de la Mora', 'query' => 'Fernando de la Mora', 'city' => 'Fernando de la Mora', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => ['Fdo. de la Mora', 'Fdo de la Mora']],
            ['type' => 'city', 'name' => 'San Juan Bautista', 'query' => 'San Juan Bautista, Misiones', 'city' => 'San Juan Bautista', 'department' => 'Misiones', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Presidente Franco', 'query' => 'Presidente Franco, Alto Paraná', 'city' => 'Presidente Franco', 'department' => 'Alto Paraná', 'accuracy' => 'city', 'aliases' => ['Pdte. Franco', 'Pdte Franco']],
            ['type' => 'city', 'name' => 'General Artigas', 'query' => 'General Artigas, Itapúa', 'city' => 'General Artigas', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => ['Gral. Artigas', 'Gral Artigas']],
            ['type' => 'city', 'name' => 'Ciudad del Este', 'query' => 'Ciudad del Este', 'city' => 'Ciudad del Este', 'department' => 'Alto Paraná', 'accuracy' => 'city', 'aliases' => ['CDE']],
            ['type' => 'city', 'name' => 'Coronel Oviedo', 'query' => 'Coronel Oviedo', 'city' => 'Coronel Oviedo', 'department' => 'Caaguazú', 'accuracy' => 'city', 'aliases' => ['Cnel. Oviedo', 'Cnel Oviedo']],
            ['type' => 'city', 'name' => 'Pedro Juan Caballero', 'query' => 'Pedro Juan Caballero', 'city' => 'Pedro Juan Caballero', 'department' => 'Amambay', 'accuracy' => 'city', 'aliases' => ['PJC']],
            ['type' => 'city', 'name' => 'San Bernardino', 'query' => 'San Bernardino, Cordillera', 'city' => 'San Bernardino', 'department' => 'Cordillera', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'San Lorenzo', 'query' => 'San Lorenzo, Central', 'city' => 'San Lorenzo', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Villa Elisa', 'query' => 'Villa Elisa, Central', 'city' => 'Villa Elisa', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Bella Vista', 'query' => 'Bella Vista, Itapúa', 'city' => 'Bella Vista', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => ['Bella Vista Sur']],
            ['type' => 'city', 'name' => 'Filadelfia', 'query' => 'Filadelfia, Boquerón', 'city' => 'Filadelfia', 'department' => 'Boquerón', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -22.3525402, 'longitude' => -60.0351707],
            ['type' => 'city', 'name' => 'Mariscal Estigarribia', 'query' => 'Mariscal Estigarribia, Boquerón', 'city' => 'Mariscal Estigarribia', 'department' => 'Boquerón', 'accuracy' => 'city', 'aliases' => ['Mcal. Estigarribia', 'Mcal Estigarribia'], 'latitude' => -22.0297927, 'longitude' => -60.5984488],
            ['type' => 'city', 'name' => 'Loma Plata', 'query' => 'Loma Plata, Boquerón', 'city' => 'Loma Plata', 'department' => 'Boquerón', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -22.3848499, 'longitude' => -59.8382177],
            ['type' => 'city', 'name' => 'Villa Hayes', 'query' => 'Villa Hayes, Presidente Hayes', 'city' => 'Villa Hayes', 'department' => 'Presidente Hayes', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -25.0928654, 'longitude' => -57.5246478],
            ['type' => 'town', 'name' => 'Pozo Colorado', 'query' => 'Pozo Colorado, Presidente Hayes', 'city' => 'Pozo Colorado', 'department' => 'Presidente Hayes', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -23.4923350, 'longitude' => -58.7972187],
            ['type' => 'city', 'name' => 'Fuerte Olimpo', 'query' => 'Fuerte Olimpo, Alto Paraguay', 'city' => 'Fuerte Olimpo', 'department' => 'Alto Paraguay', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -21.0385406, 'longitude' => -57.8748123],
            ['type' => 'city', 'name' => 'Puerto Casado', 'query' => 'Puerto Casado, Alto Paraguay', 'city' => 'Puerto Casado', 'department' => 'Alto Paraguay', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -22.2857460, 'longitude' => -57.9431435],
            ['type' => 'city', 'name' => 'Bahía Negra', 'query' => 'Bahía Negra, Alto Paraguay', 'city' => 'Bahía Negra', 'department' => 'Alto Paraguay', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Carmelo Peralta', 'query' => 'Carmelo Peralta, Alto Paraguay', 'city' => 'Carmelo Peralta', 'department' => 'Alto Paraguay', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Nanawa', 'query' => 'Nanawa, Presidente Hayes', 'city' => 'Nanawa', 'department' => 'Presidente Hayes', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -25.2803256, 'longitude' => -57.7029628],
            ['type' => 'city', 'name' => 'Encarnación', 'query' => 'Encarnación, Itapúa', 'city' => 'Encarnación', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Cambyretá', 'query' => 'Cambyretá, Itapúa', 'city' => 'Cambyretá', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => ['Cambyreta']],
            ['type' => 'city', 'name' => 'Asunción', 'query' => 'Asunción', 'city' => 'Asunción', 'department' => 'Capital', 'accuracy' => 'city', 'aliases' => ['Capital del país'], 'latitude' => -25.2800459, 'longitude' => -57.6343814],
            ['type' => 'city', 'name' => 'Caacupé', 'query' => 'Caacupé, Cordillera', 'city' => 'Caacupé', 'department' => 'Cordillera', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -25.3856888, 'longitude' => -57.1404770],
            // GeoNames populated-place coordinates for localities cited by current Itapúa reporting.
            ['type' => 'city', 'name' => 'Coronel Bogado', 'query' => 'Coronel Bogado, Itapúa', 'city' => 'Coronel Bogado', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => ['Coronel José Félix Bogado', 'Cnel. Bogado', 'Cnel Bogado'], 'latitude' => -27.16293, 'longitude' => -56.24023],
            ['type' => 'city', 'name' => 'General Delgado', 'query' => 'General Delgado, Itapúa', 'city' => 'General Delgado', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => ['General José María Delgado', 'Gral. Delgado', 'Gral Delgado'], 'latitude' => -27.09046, 'longitude' => -56.52474],
            ['type' => 'city', 'name' => 'Capitán Meza', 'query' => 'Capitán Meza, Itapúa', 'city' => 'Capitán Meza', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Carapeguá', 'query' => 'Carapeguá, Paraguarí', 'city' => 'Carapeguá', 'department' => 'Paraguarí', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Capiatá', 'query' => 'Capiatá, Central', 'city' => 'Capiatá', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => [], 'latitude' => -25.3548171, 'longitude' => -57.4427315],
            ['type' => 'city', 'name' => 'Lambaré', 'query' => 'Lambaré, Central', 'city' => 'Lambaré', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Ypacaraí', 'query' => 'Ypacaraí, Central', 'city' => 'Ypacaraí', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Paraguarí', 'query' => 'Paraguarí', 'city' => 'Paraguarí', 'department' => 'Paraguarí', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Villarrica', 'query' => 'Villarrica, Guairá', 'city' => 'Villarrica', 'department' => 'Guairá', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Concepción', 'query' => 'Concepción, Paraguay', 'city' => 'Concepción', 'department' => 'Concepción', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Pilar', 'query' => 'Pilar, Ñeembucú', 'city' => 'Pilar', 'department' => 'Ñeembucú', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Luque', 'query' => 'Luque, Central', 'city' => 'Luque', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Limpio', 'query' => 'Limpio, Central', 'city' => 'Limpio', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Ñemby', 'query' => 'Ñemby, Central', 'city' => 'Ñemby', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'San Antonio', 'query' => 'San Antonio, Central', 'city' => 'San Antonio', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Julián Augusto Saldívar', 'query' => 'Julián Augusto Saldívar, Central', 'city' => 'Julián Augusto Saldívar', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => ['J. Augusto Saldívar', 'J.A. Saldívar']],
            ['type' => 'city', 'name' => 'Salto del Guairá', 'query' => 'Salto del Guairá, Canindeyú', 'city' => 'Salto del Guairá', 'department' => 'Canindeyú', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Nueva Italia', 'query' => 'Nueva Italia, Central', 'city' => 'Nueva Italia', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Hernandarias', 'query' => 'Hernandarias, Alto Paraná', 'city' => 'Hernandarias', 'department' => 'Alto Paraná', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Minga Guazú', 'query' => 'Minga Guazú, Alto Paraná', 'city' => 'Minga Guazú', 'department' => 'Alto Paraná', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Guarambaré', 'query' => 'Guarambaré, Central', 'city' => 'Guarambaré', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Piribebuy', 'query' => 'Piribebuy, Cordillera', 'city' => 'Piribebuy', 'department' => 'Cordillera', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Itauguá', 'query' => 'Itauguá, Central', 'city' => 'Itauguá', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Areguá', 'query' => 'Areguá, Central', 'city' => 'Areguá', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Tobatí', 'query' => 'Tobatí, Cordillera', 'city' => 'Tobatí', 'department' => 'Cordillera', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Natalio', 'query' => 'Natalio, Itapúa', 'city' => 'Natalio', 'department' => 'Itapúa', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Atyrá', 'query' => 'Atyrá, Cordillera', 'city' => 'Atyrá', 'department' => 'Cordillera', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'city', 'name' => 'Ypané', 'query' => 'Ypané, Central', 'city' => 'Ypané', 'department' => 'Central', 'accuracy' => 'city', 'aliases' => []],
            ['type' => 'neighborhood', 'name' => 'Pedrozo', 'query' => 'Pedrozo, Ypacaraí', 'city' => 'Ypacaraí', 'department' => 'Central', 'accuracy' => 'neighborhood', 'aliases' => [], 'latitude' => -25.3679614, 'longitude' => -57.2169014],
            ['type' => 'department', 'name' => 'Alto Paraguay', 'query' => 'Departamento de Alto Paraguay', 'city' => null, 'department' => 'Alto Paraguay', 'accuracy' => 'department', 'aliases' => ['Departamento de Alto Paraguay'], 'latitude' => -20.5000000, 'longitude' => -59.1666670],
            ['type' => 'department', 'name' => 'Presidente Hayes', 'query' => 'Departamento de Presidente Hayes', 'city' => null, 'department' => 'Presidente Hayes', 'accuracy' => 'department', 'aliases' => ['Departamento de Presidente Hayes'], 'latitude' => -23.5000000, 'longitude' => -58.8333330],
            ['type' => 'department', 'name' => 'Alto Paraná', 'query' => 'Departamento de Alto Paraná', 'city' => null, 'department' => 'Alto Paraná', 'accuracy' => 'department', 'aliases' => ['Departamento de Alto Paraná'], 'latitude' => -25.5184729, 'longitude' => -54.6608590],
            ['type' => 'department', 'name' => 'Canindeyú', 'query' => 'Departamento de Canindeyú', 'city' => null, 'department' => 'Canindeyú', 'accuracy' => 'department', 'aliases' => ['Departamento de Canindeyú']],
            ['type' => 'department', 'name' => 'Ñeembucú', 'query' => 'Departamento de Ñeembucú', 'city' => null, 'department' => 'Ñeembucú', 'accuracy' => 'department', 'aliases' => ['Departamento de Ñeembucú']],
            ['type' => 'department', 'name' => 'Cordillera', 'query' => 'Departamento de Cordillera', 'city' => null, 'department' => 'Cordillera', 'accuracy' => 'department', 'aliases' => ['Departamento de Cordillera'], 'latitude' => -25.2500000, 'longitude' => -57.0000000],
            ['type' => 'department', 'name' => 'Caaguazú', 'query' => 'Departamento de Caaguazú', 'city' => null, 'department' => 'Caaguazú', 'accuracy' => 'department', 'aliases' => ['Departamento de Caaguazú']],
            ['type' => 'department', 'name' => 'Caazapá', 'query' => 'Departamento de Caazapá', 'city' => null, 'department' => 'Caazapá', 'accuracy' => 'department', 'aliases' => ['Departamento de Caazapá']],
            ['type' => 'department', 'name' => 'Amambay', 'query' => 'Departamento de Amambay', 'city' => null, 'department' => 'Amambay', 'accuracy' => 'department', 'aliases' => ['Departamento de Amambay']],
            ['type' => 'department', 'name' => 'Boquerón', 'query' => 'Departamento de Boquerón', 'city' => null, 'department' => 'Boquerón', 'accuracy' => 'department', 'aliases' => ['Departamento de Boquerón'], 'latitude' => -22.6643064, 'longitude' => -61.0015877],
            ['type' => 'department', 'name' => 'Itapúa', 'query' => 'Departamento de Itapúa', 'city' => null, 'department' => 'Itapúa', 'accuracy' => 'department', 'aliases' => ['Departamento de Itapúa']],
            ['type' => 'department', 'name' => 'Misiones', 'query' => 'Departamento de Misiones', 'city' => null, 'department' => 'Misiones', 'accuracy' => 'department', 'aliases' => ['Departamento de Misiones']],
            ['type' => 'department', 'name' => 'San Pedro', 'query' => 'Departamento de San Pedro', 'city' => null, 'department' => 'San Pedro', 'accuracy' => 'department', 'aliases' => ['Departamento de San Pedro']],
            ['type' => 'department', 'name' => 'Central', 'query' => 'Departamento Central, Paraguay', 'city' => null, 'department' => 'Central', 'accuracy' => 'department', 'aliases' => ['Departamento Central']],
            ['type' => 'department', 'name' => 'Departamento de Concepción', 'query' => 'Departamento de Concepción, Paraguay', 'city' => null, 'department' => 'Concepción', 'accuracy' => 'department', 'aliases' => []],
            ['type' => 'department', 'name' => 'Departamento de Guairá', 'query' => 'Departamento de Guairá, Paraguay', 'city' => null, 'department' => 'Guairá', 'accuracy' => 'department', 'aliases' => []],
            ['type' => 'department', 'name' => 'Departamento de Paraguarí', 'query' => 'Departamento de Paraguarí, Paraguay', 'city' => null, 'department' => 'Paraguarí', 'accuracy' => 'department', 'aliases' => []],
        ];
    }

    private function normalize(string $value): string
    {
        $normalized = Str::lower(Str::ascii(trim($value)));

        return preg_replace('/\s+/u', ' ', $normalized) ?: $normalized;
    }
}

