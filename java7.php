<?php
// Configuración de la base de datos
$db_host = 'localhost';
$db_name = 'placeholder01_modelev';
$db_user = 'placeholder01_animeonegaii';
$db_pass = '4MF9noksWHlDHKJh(Y';

// Conectar a la base de datos
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Función para obtener el HTML de una URL
function getHTML($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

// Función para verificar si el slug existe en la base de datos
function slugExists($pdo, $slug) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM animes WHERE slug = ?");
    $stmt->execute([$slug]);
    return $stmt->fetchColumn() > 0;
}

// Función para verificar si la temporada existe
function seasonExists($pdo, $anime_id, $season_number) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM anime_seasons WHERE anime_id = ? AND season_number = ?");
    $stmt->execute([$anime_id, $season_number]);
    return $stmt->fetchColumn() > 0;
}

// Función para verificar si el episodio existe
function episodeExists($pdo, $season_id, $episode_number) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM anime_episodes WHERE anime_season_id = ? AND episode_number = ?");
    $stmt->execute([$season_id, $episode_number]);
    return $stmt->fetchColumn() > 0;
}

// Función para verificar si el reproductor existe
function videoExists($pdo, $episode_id, $link) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM anime_videos WHERE anime_episode_id = ? AND link = ?");
    $stmt->execute([$episode_id, $link]);
    return $stmt->fetchColumn() > 0;
}

// Función para extraer datos del anime
function extractAnimeData($html) {
    $data = [];
    
    // Extraer título - buscar el h3 dentro de la estructura correcta
    if (preg_match('/<div class="anime__details__title"[^>]*>.*?<h3>([^<]+)<\/h3>/s', $html, $matches)) {
        $data['title'] = trim($matches[1]);
    }
    
    // Extraer sinopsis
    if (preg_match('/<p class="tab sinopsis">([^<]+)<\/p>/', $html, $matches)) {
        $data['synopsis'] = trim($matches[1]);
    }
    
    // Extraer imagen de portada desde la meta tag og:image
    if (preg_match('/<meta property="og:image" content=[\'"]([^\'"]+)[\'"]/', $html, $matches)) {
        $data['poster'] = $matches[1];
    }
    
    // Extraer fecha de emisión y convertirla
    if (preg_match('/<li><span>Emitido:<\/span>\s*([A-Za-z]+\s+\d+\s+de\s+\d{4})\s+a/', $html, $matches)) {
        $fecha_texto = trim($matches[1]); // e.g., "Oct 1 de 1976"
        
        // Array para convertir meses en español/inglés a números
        $meses = [
            'Jan' => '01', 'Ene' => '01', 'January' => '01', 'Enero' => '01',
            'Feb' => '02', 'Febrero' => '02', 'February' => '02',
            'Mar' => '03', 'Marzo' => '03', 'March' => '03',
            'Apr' => '04', 'Abr' => '04', 'Abril' => '04', 'April' => '04',
            'May' => '05', 'Mayo' => '05',
            'Jun' => '06', 'Junio' => '06', 'June' => '06',
            'Jul' => '07', 'Julio' => '07', 'July' => '07',
            'Aug' => '08', 'Ago' => '08', 'Agosto' => '08', 'August' => '08',
            'Sep' => '09', 'Sept' => '09', 'Septiembre' => '09', 'September' => '09',
            'Oct' => '10', 'Octubre' => '10', 'October' => '10',
            'Nov' => '11', 'Noviembre' => '11', 'November' => '11',
            'Dec' => '12', 'Dic' => '12', 'Diciembre' => '12', 'December' => '12'
        ];
        
        // Extraer partes de la fecha
        if (preg_match('/([A-Za-z]+)\s+(\d+)\s+de\s+(\d{4})/', $fecha_texto, $partes)) {
            $mes_texto = $partes[1];
            $dia = str_pad($partes[2], 2, '0', STR_PAD_LEFT);
            $año = $partes[3];
            
            if (isset($meses[$mes_texto])) {
                $mes = $meses[$mes_texto];
                $data['first_air_date'] = "{$año}-{$mes}-{$dia}";
            }
        }
    }
    
    // Extraer trailer
    if (preg_match('/data-yt="([^"]+)"/', $html, $matches)) {
        $data['trailer'] = $matches[1];
    }
    
    // Extraer géneros
    if (preg_match('/<li><span>Genero:<\/span>(.*?)<\/li>/s', $html, $matches)) {
        $generos_html = $matches[1];
        preg_match_all('/<a[^>]+>([^<]+)<\/a>/', $generos_html, $generos_matches);
        if (!empty($generos_matches[1])) {
            $data['genres'] = array_map('trim', $generos_matches[1]);
        }
    }
    
    return $data;
}

// Función para descargar y guardar la imagen
function downloadAndSaveImage($imageUrl, $slug) {
    // Crear directorio si no existe
    $directory = __DIR__ . '/storage/app/animes/' . $slug;
    if (!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }
    
    // Generar nombre de archivo aleatorio
    $randomString = substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 32)), 0, 32);
    $filename = $randomString . '.webp';
    $filepath = $directory . '/' . $filename;
    
    // Descargar la imagen
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $imageData = curl_exec($ch);
    curl_close($ch);
    
    if ($imageData) {
        // Guardar la imagen
        file_put_contents($filepath, $imageData);
        
        // Retornar la URL relativa para la base de datos
        return "https://mimitomi.org/storage/animes/{$slug}/{$filename}";
    }
    
    return null;
}

// Función para obtener o insertar género
function getOrCreateGenre($pdo, $genre_name) {
    // Verificar si el género ya existe
    $stmt = $pdo->prepare("SELECT id FROM genres WHERE name = ?");
    $stmt->execute([$genre_name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        return $result['id'];
    }
    
    // Si no existe, insertarlo
    $stmt = $pdo->prepare("INSERT INTO genres (name, created_at, updated_at) VALUES (?, NOW(), NOW())");
    $stmt->execute([$genre_name]);
    return $pdo->lastInsertId();
}

// Función para crear relación anime-género
function createAnimeGenreRelation($pdo, $anime_id, $genre_id) {
    try {
        // Verificar si la relación ya existe
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM anime_genres WHERE anime_id = ? AND genre_id = ?");
        $stmt->execute([$anime_id, $genre_id]);
        if ($stmt->fetchColumn() > 0) {
            echo "La relación entre anime ID {$anime_id} y género ID {$genre_id} ya existe.\n";
            return true;
        }
        
        $stmt = $pdo->prepare("INSERT INTO anime_genres (anime_id, genre_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
        $stmt->execute([$anime_id, $genre_id]);
        return true;
    } catch (PDOException $e) {
        echo "Error al crear relación anime-género: " . $e->getMessage() . "\n";
        return false;
    }
}

// Nueva función para extraer el número total de episodios
function extractTotalEpisodes($html) {
    // Buscar el último número en la paginación
    if (preg_match_all('/<a class="numbers"[^>]*>(\d+)\s*-\s*(\d+)<\/a>/', $html, $matches)) {
        // Obtener el último número del último rango
        $last_range = end($matches[2]);
        return intval($last_range);
    }
    return 0;
}

// Nueva función para descargar y guardar imagen de episodio
function downloadAndSaveEpisodeImage($imageUrl, $slug, $episode_number) {
    // Crear directorio si no existe
    $directory = __DIR__ . '/storage/app/animes/' . $slug . '/chapter';
    if (!file_exists($directory)) {
        mkdir($directory, 0777, true);
    }
    
    $filename = $episode_number . '.webp';
    $filepath = $directory . '/' . $filename;
    
    // Descargar la imagen
    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $imageData = curl_exec($ch);
    curl_close($ch);
    
    if ($imageData) {
        // Guardar la imagen
        file_put_contents($filepath, $imageData);
        
        // Retornar la URL relativa para la base de datos
        return "https://mimitomi.org/storage/animes/{$slug}/chapter/{$filename}";
    }
    
    return null;
}

// Nueva función para extraer reproductores de video
function extractVideoPlayers($html) {
    $players = [];
    
    // Primero: Extraer reproductores iframes del array video[]
    if (preg_match('/<!-- CARGAR REPRODUCTORES AISLADOS -->\s*<script>\s*var video = \[\];(.*?)if\(video\[1\]/s', $html, $matches)) {
        $video_js = $matches[1];
        preg_match_all('/video\[(\d+)\] = \'<iframe[^>]*src="([^"]+)"/', $video_js, $iframe_matches, PREG_SET_ORDER);
        
        foreach ($iframe_matches as $match) {
            $index = $match[1];
            $src = $match[2];
            
            // Completar la URL con https://jkanime.org
            if (strpos($src, 'http') !== 0) {
                $src = 'https://jkanime.org' . $src;
            }
            
            $players[] = [
                'server' => $index == 1 ? '720P' : '480P',
                'header' => 'https://jkanime.org',
                'link' => $src,
                'video_name' => 'Server ' . $index,
                'type' => 'iframe'
            ];
        }
    }
    
    // Segundo: Extraer reproductores en base64 en orden específico
    if (preg_match('/var servers = (\[.*?\]);/s', $html, $matches)) {
        $servers_json = $matches[1];
        $servers = json_decode($servers_json, true);
        
        if ($servers && is_array($servers)) {
            // Orden específico de servidores
            $target_servers_order = ['Streamwish', 'Vidhide', 'Mp4upload', 'VOE'];
            $found_servers = [];
            
            // Clasificar servidores según el orden especificado
            foreach ($servers as $server) {
                if (in_array($server['server'], $target_servers_order)) {
                    $found_servers[$server['server']] = $server;
                }
            }
            
            // Agregar servidores en el orden especificado
            $server_count = count($players) + 1; // Continuar la numeración desde el último servidor de video[]
            
            foreach ($target_servers_order as $server_name) {
                if (isset($found_servers[$server_name])) {
                    $server = $found_servers[$server_name];
                    
                    // Decodificar URL en base64
                    $remote_url = base64_decode($server['remote']);
                    $remote_url = trim($remote_url);
                    
                    // Obtener dominio para header
                    $header = '';
                    if (preg_match('|^https?://([^/]+)|', $remote_url, $domain_match)) {
                        $header = 'https://' . $domain_match[1] . '/';
                    }
                    
                    $players[] = [
                        'server' => $server_count <= 1 ? '720P' : '480P',
                        'header' => $header,
                        'link' => $remote_url,
                        'video_name' => 'Server ' . $server_count,
                        'type' => 'b64'
                    ];
                    
                    $server_count++;
                }
            }
        }
    }
    
    return $players;
}

// Función para insertar reproductores de video con validación
function insertVideoPlayers($pdo, $episode_id, $players) {
    $count = 0;
    
    foreach ($players as $player) {
        // Verificar si el reproductor ya existe
        if (!videoExists($pdo, $episode_id, $player['link'])) {
            $sql = "INSERT INTO anime_videos (
                anime_episode_id, server, header, link, lang,
                video_name, embed, youtubelink, hls, supported_hosts,
                drm, status, created_at, updated_at
            ) VALUES (
                :episode_id, :server, :header, :link, :lang,
                :video_name, 0, 0, 0, 1, 0, 1, NOW(), NOW()
            )";
            
            $stmt = $pdo->prepare($sql);
            try {
                $stmt->execute([
                    ':episode_id' => $episode_id,
                    ':server' => $player['server'],
                    ':header' => $player['header'],
                    ':link' => $player['link'],
                    ':lang' => 'Spanish',
                    ':video_name' => $player['video_name']
                ]);
                $count++;
            } catch (PDOException $e) {
                echo "Error al insertar reproductor: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Reproductor con link '{$player['link']}' ya existe para el episodio ID: {$episode_id}\n";
        }
    }
    
    return $count;
}

// Nueva función para procesar episodios con validación
function processEpisodes($pdo, $anime_id, $slug, $total_episodes) {
    echo "Procesando {$total_episodes} episodios para {$slug}\n";
    
    $current_season = 1;
    $season_id = null;
    
    for ($i = 1; $i <= $total_episodes; $i++) {
        // Si es el primer episodio de una temporada nueva, crear la temporada
        if (($i - 1) % 12 == 0) {
            // Verificar si la temporada ya existe
            if (!seasonExists($pdo, $anime_id, $current_season)) {
                // Crear nueva temporada
                $sql = "INSERT INTO anime_seasons (
                    anime_id, season_number, name, created_at, updated_at
                ) VALUES (
                    :anime_id, :season_number, :name, NOW(), NOW()
                )";
                
                $stmt = $pdo->prepare($sql);
                try {
                    $stmt->execute([
                        ':anime_id' => $anime_id,
                        ':season_number' => $current_season,
                        ':name' => "Temporada {$current_season}"
                    ]);
                    
                    $season_id = $pdo->lastInsertId();
                    echo "Temporada {$current_season} creada con ID: {$season_id}\n";
                } catch (PDOException $e) {
                    echo "Error al crear temporada: " . $e->getMessage() . "\n";
                    continue;
                }
            } else {
                // Obtener el ID de la temporada existente
                $stmt = $pdo->prepare("SELECT id FROM anime_seasons WHERE anime_id = ? AND season_number = ?");
                $stmt->execute([$anime_id, $current_season]);
                $season_id = $stmt->fetchColumn();
                echo "Temporada {$current_season} ya existe con ID: {$season_id}\n";
            }
            
            $current_season++;
        }
        
        // Verificar si el episodio ya existe
        if (!episodeExists($pdo, $season_id, $i)) {
            // Obtener página del episodio
            $episode_url = "https://jkanime.org/{$slug}/{$i}/";
            $episode_html = getHTML($episode_url);
            
            // Extraer imagen del episodio
            $episode_image = null;
            if (preg_match('/<meta property="og:image" content=[\'"]([^\'"]+)[\'"]/', $episode_html, $matches)) {
                $episode_image = downloadAndSaveEpisodeImage($matches[1], $slug, $i);
            }
            
            // Insertar episodio
            $sql = "INSERT INTO anime_episodes (
                anime_season_id, episode_number, name, still_path, still_path_tv,
                vote_average, views, skiprecap_start_in, hasrecap, enable_stream,
                enable_media_download, enable_ads_unlock, created_at, updated_at
            ) VALUES (
                :anime_season_id, :episode_number, :name, :still_path, :still_path_tv,
                0.00, 0, 0, 0, 1, 1, 0, NOW(), NOW()
            )";
            
            $stmt = $pdo->prepare($sql);
            try {
                $stmt->execute([
                    ':anime_season_id' => $season_id,
                    ':episode_number' => $i,
                    ':name' => "Capítulo {$i}",
                    ':still_path' => $episode_image,
                    ':still_path_tv' => $episode_image
                ]);
                
                $episode_id = $pdo->lastInsertId();
                echo "Episodio {$i} insertado correctamente con ID: {$episode_id}\n";
                
                // Extraer y procesar reproductores de video
                $players = extractVideoPlayers($episode_html);
                if (!empty($players)) {
                    $count = insertVideoPlayers($pdo, $episode_id, $players);
                    echo "Se insertaron {$count} reproductores para el episodio {$i}\n";
                }
                
            } catch (PDOException $e) {
                echo "Error al insertar episodio {$i}: " . $e->getMessage() . "\n";
            }
        } else {
            echo "Episodio {$i} ya existe para la temporada con ID: {$season_id}\n";
        }
    }
}

// URL base
$base_url = 'https://jkanime.org/directorio/168/';

// Obtener HTML de la página de directorio
$html = getHTML($base_url);

// Extraer array de animes
if (preg_match('/var\s+animes\s*=\s*(\[.+?\]);/s', $html, $matches)) {
    $animes = json_decode($matches[1], true);
    
    if ($animes && is_array($animes)) {
        foreach ($animes as $anime) {
            if (isset($anime['slug'])) {
                $slug = $anime['slug'];
                
                // Verificar si el slug ya existe en la base de datos
                if (!slugExists($pdo, $slug)) {
                    echo "Procesando anime: {$slug}\n";
                    
                    // Obtener página del anime
                    $anime_url = "https://jkanime.org/{$slug}/";
                    $anime_html = getHTML($anime_url);
                    
                    // Extraer datos del anime
                    $anime_data = extractAnimeData($anime_html);
                    
                    if (!empty($anime_data['title'])) {
                        // Descargar y guardar la imagen
                        $poster_path = null;
                        if (!empty($anime_data['poster'])) {
                            $poster_path = downloadAndSaveImage($anime_data['poster'], $slug);
                            if ($poster_path) {
                                echo "Imagen descargada correctamente para '{$anime_data['title']}'\n";
                            } else {
                                echo "Error al descargar la imagen para '{$anime_data['title']}'\n";
                            }
                        }
                        
                        $trailer_url = $anime_data['trailer'] ?? '';

                        // Preparar consulta de inserción
// Preparar consulta de inserción
$sql = "INSERT INTO animes (
    name, original_name, slug, overview, poster_path, 
    backdrop_path_tv, backdrop_path, preview_path, trailer_url,
    active, is_anime, premuim, pinned, newEpisodes, featured,
    first_air_date, imdb_external_id, created_at, updated_at
) VALUES (
    :name, :original_name, :slug, :overview, :poster_path,
    :backdrop_path_tv, :backdrop_path, :preview_path, :trailer_url,
    1, 1, 0, 0, 0, 0, :first_air_date, '', NOW(), NOW()
)";

                        $stmt = $pdo->prepare($sql);
                        
                        // Ejecutar inserción
                        try {
                            $stmt->execute([
                                ':name' => $anime_data['title'],
                                ':original_name' => $anime_data['title'],
                                ':slug' => $slug,
                                ':overview' => $anime_data['synopsis'] ?? '',
                                ':poster_path' => $poster_path,
                                ':backdrop_path_tv' => $poster_path,
                                ':backdrop_path' => $poster_path,
                                ':preview_path' => $trailer_url,
                                ':trailer_url' => null,
                                ':first_air_date' => $anime_data['first_air_date'] ?? null
                            ]);
                            
                            $anime_id = $pdo->lastInsertId();
                            echo "Anime '{$anime_data['title']}' insertado correctamente con ID: {$anime_id}\n";
                            
                            // Procesar géneros
                            if (!empty($anime_data['genres'])) {
                                foreach ($anime_data['genres'] as $genre_name) {
                                    $genre_id = getOrCreateGenre($pdo, $genre_name);
                                    if (createAnimeGenreRelation($pdo, $anime_id, $genre_id)) {
                                        echo "Género '{$genre_name}' asociado correctamente al anime.\n";
                                    }
                                }
                            }
                            
                            // Extraer total de episodios
                            $total_episodes = extractTotalEpisodes($anime_html);
                            
                            if ($total_episodes > 0) {
                                // Procesar episodios y temporadas
                                processEpisodes($pdo, $anime_id, $slug, $total_episodes);
                            }
                            
                        } catch (PDOException $e) {
                            echo "Error al insertar anime '{$anime_data['title']}': " . $e->getMessage() . "\n";
                        }
                    } else {
                        echo "No se pudieron extraer datos para el anime: {$slug}\n";
                    }
                } else {
                    echo "El anime '{$slug}' ya existe en la base de datos. Saltando...\n";
                }
            }
        }
    } else {
        echo "No se pudo decodificar el array de animes.\n";
    }
} else {
    echo "No se encontró el script con var animes.\n";
}
?>