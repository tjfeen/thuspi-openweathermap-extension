<?php 
    $extension = new \thusPi\Extensions\Extension(EXTENSION_ID);
    
    // Load the config
    $owm_config = $extension->getData('openweathermap_config');

    // Load the cached response
    $owm_cached = $extension->getData('openweathermap_cached');

    // Exit if config is incomplete
    if(!isset($owm_config['appid']) || !isset($owm_config['lat']) || !isset($owm_config['lon'])) {
        exit();
    }

    // Urlencode items
    $owm_config['appid'] = urlencode($owm_config['appid']);
    $owm_config['lat']   = urlencode($owm_config['lat']);
    $owm_config['lon']   = urlencode($owm_config['lon']);

    // Load weather data from server if the cached version
    // doesn't exist or is older than 10 minutes
    if(!isset($owm_cached) || $owm_cached['fetched_at'] + 160 < time()) {
        $ch = curl_init("https://api.openweathermap.org/data/2.5/onecall?lat={$owm_config['lat']}&lon={$owm_config['lon']}&appid={$owm_config['appid']}&exclude=daily,alerts");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $weather_json = curl_exec($ch);
        curl_close($ch);
        
        if(!$weather = @json_decode($weather_json, true)) {
            exit();
        }

        // Save the new weather data
        $extension->saveData('openweathermap_cached', ['fetched_at' => time(), 'weather' => $weather]);
    } else {
        // Use weather response from cache
        $weather = $owm_cached['weather'];
    }

    // Create weather description
    preg_match(
        '/(thunderstorm|shower|rain|drizzle|snow|mist|overcast_clouds|broken_clouds|scattered_clouds|few_clouds|clear_sky)/',
        str_replace(' ', '_', $weather['current']['weather'][0]['description']),
        $current_weather_types
    );

    // Get the most important weather type at this time
    $current_weather_type = $current_weather_types[0];

    // Get the temperature unit and symbol preferred by the user
    $temperature_unit   = \thusPi\Users\CurrentUser::getSetting('measurement_system') == 'imperial' ? 'fahrenheit' : 'celsius';
    $temperature_symbol = $temperature_unit == 'celsius' ? '°C' : '°F';

    $weather_formatted = [
        'icon' => owm_remap_icon($weather['current']['weather'][0]['icon'] ?? null),
        'description' => $extension->translate(TRANSLATION_PREFIX."weather.description.{$current_weather_type}"),
        'precipitation' => $weather['current']['precipitation'] ?? 0,
        'precipitation_changes_at' => owm_find_precipitation_change_dt($weather),
        'humidity' => $weather['current']['humidity'] ?? 'n/a',
        'clouds' => $weather['current']['clouds'] ?? 'n/a',
        'temperature' => number_format(convert_kelvin_to($temperature_unit, $weather['current']['temp']), 1) ?? 'n/a',
        'feels_like' => number_format(convert_kelvin_to($temperature_unit, $weather['current']['feels_like']), 1) ?? 'n/a'
    ];
?>
<div class="weather">
    <div class="row flex-nowrap mb-2">
        <div class="col-auto">
            <?php 
                echo(create_icon($weather_formatted['icon'], 'xl', [strpos($weather_formatted['icon'], 'sun') > 0 ? 'text-warning' : 'text-info']));
            ?>
        </div>
        <div class="col ml-3">
            <span class="text-overflow-ellipsis d-block"><?php echo($weather_formatted['description']); ?></span>
            <span class="text-overflow-ellipsis text-muted"><?php 
                if($weather_formatted['precipitation'] > 0) {
                    echo($extension->translate(
                        TRANSLATION_PREFIX.'weather.information.precipitation_ends_in', 
                        [thusPi\Locale\date_format_diff($weather_formatted['precipitation_changes_at'])]
                    ));
                } else {
                    // If precipitation is forecasted, show a message to the user
                    if($weather_formatted['precipitation_changes_at'] > 0) {
                        echo($extension->translate(
                            TRANSLATION_PREFIX.'weather.information.next_precipitation_in', 
                            [\thusPi\Locale\date_format_diff($weather_formatted['precipitation_changes_at'])]
                        ));
                    } else {
                        echo($extension->translate(
                            TRANSLATION_PREFIX.'weather.information.no_precipitation_forecasted'
                        ));
                    }
                }
            ?></span>
        </div>
    </div>
    <div class="d-flex flex-column">
        <div class="d-flex flex-row align-items-center mb-2">
            <?php echo(create_icon('far.humidity', null, ['text-success'])); ?>
            <?php echo($extension->translate(TRANSLATION_PREFIX."weather.property.humidity")); ?>
            <span class="ml-auto"><?php echo($weather_formatted['humidity']); ?>%</span>
        </div>
        <div class="d-flex flex-row align-items-center mb-2">
            <?php echo(create_icon('far.cloud', null, ['text-info'])); ?>
            <?php echo($extension->translate(TRANSLATION_PREFIX."weather.property.clouds")); ?>
            <span class="ml-auto"><?php echo($weather_formatted['clouds']); ?>%</span>
        </div>
        <div class="d-flex flex-row align-items-center mb-2">
            <?php echo(create_icon('far.thermometer-quarter', null, ['text-caution'])); ?>
            <?php echo($extension->translate(TRANSLATION_PREFIX."weather.property.temperature")); ?>
            <span class="ml-auto"><?php echo($weather_formatted['temperature']); ?><?php echo($temperature_symbol); ?></span>
        </div>
        <div class="d-flex flex-row align-items-center mb-2">
            <?php echo(create_icon('far.thermometer-quarter', null, ['text-warning'])); ?>
            <?php echo($extension->translate(TRANSLATION_PREFIX."weather.property.feels_like")); ?>
            <span class="ml-auto"><?php echo($weather_formatted['feels_like']); ?><?php echo($temperature_symbol); ?></span>
        </div>
    </div>
    <div class="weather-forecast">
    </div>
</div>
<?php
    function owm_find_precipitation_change_dt($weather) {
        // Check if there is currently precipitation
        $precipitation_now = isset($weather['current']['precipitation']);

        // Check the minutely weather for a precipitation change
        foreach ($weather['minutely'] as $minutes_away => $minutely_weather) {
            // Continue if this minute has already passed
            if($minutely_weather['dt'] < time()) {
                continue;
            }

            // Check when the current precipitation will end or
            // when new precipitation will come
            if(($minutely_weather['precipitation'] > 0) !== $precipitation_now) {
                return $minutely_weather['dt'];
            }
        }
        
        // Check the hourly weather for a precipitation change
        foreach ($weather['hourly'] as $hours_away => $hourly_weather) {
            // Continue if this hour has already passed
            if($hourly_weather['dt'] < time()) {
                continue;
            }

            // Only check for the next 24 hours
            if($hours_away > 24) {
                return 0;
            }

            // Check when the current precipitation will end or
            // when new precipitation will come
            if(isset($hourly_weather['precipitation']) !== $precipitation_now) {
                return $hourly_weather['dt'];
            }
        }
    }

    function convert_kelvin_to($unit = 'celsius', $kelvin) {
        if(!is_numeric($kelvin)) {
            return null;
        }

        if($unit == 'fahrenheit') {
            return 1.8 * ($kelvin - 273.15) + 32;
        }

        return $kelvin - 273.15;
    }

    function owm_remap_icon($owm_icon) {
        // Determine if a sun or moon should be present in the icon
        $celestial_body = strpos($owm_icon, 'd') !== false ? 'sun' : 'moon';

        // Remove any letters so only the numbers remain
        $owm_icon = preg_replace('/[^0-9]+/', '', $owm_icon);

        $map = [
            '01' => "far.{$celestial_body}",                              // Clear sky
            '02' => "far.{$celestial_body}-cloud",                        // Few clouds
            '03' => "far.cloud-{$celestial_body}",                        // Scattered clouds
            '04' => "far.clouds-{$celestial_body}",                       // Broken clouds
            '09' => "far.cloud-{$celestial_body}-rain",                   // Shower rain
            '10' => "far.cloud-{$celestial_body}-rain",                   // Rain
            '11' => "far.thunderstorm-{$celestial_body}",                 // Thunderstorm
            '13' => "far.cloud-snow",                                     // Snow
            '50' => $celestial_body == 'sun' ? 'far.sun-haze' : 'far.fog' // Mist
        ];

        // Return the icon name
        return $map[$owm_icon] ?? 'far.fa-question';
    }
?>