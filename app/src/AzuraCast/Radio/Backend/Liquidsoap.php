<?php
namespace AzuraCast\Radio\Backend;

use Doctrine\ORM\EntityManager;
use Entity;

class Liquidsoap extends BackendAbstract
{
    /**
     * @inheritdoc
     */
    public function read()
    {
        // This function not implemented for LiquidSoap.
    }

    /**
     * Write configuration from Station object to the external service.
     *
     * Special thanks to the team of PonyvilleFM for assisting with Liquidsoap configuration and debugging.
     *
     * @return bool
     * @throws \Doctrine\ORM\OptimisticLockException
     * @throws \Doctrine\ORM\ORMException
     */
    public function write()
    {
        $settings = (array)$this->station->getBackendConfig();

        $playlist_path = $this->station->getRadioPlaylistsDir();
        $config_path = $this->station->getRadioConfigDir();

        $charset = $settings['charset'] ?? 'UTF-8';

        $ls_config = [
            '# WARNING! This file is automatically generated by AzuraCast.',
            '# Do not update it directly!',
            '',
            'set("init.daemon", false)',
            'set("init.daemon.pidfile.path","' . $config_path . '/liquidsoap.pid")',
            'set("log.file.path","' . $config_path . '/liquidsoap.log")',
            (APP_INSIDE_DOCKER ? 'set("log.stdout", true)' : ''),
            'set("server.telnet",true)',
            'set("server.telnet.bind_addr","'.(APP_INSIDE_DOCKER ? '0.0.0.0' : '127.0.0.1').'")',
            'set("server.telnet.port", ' . $this->_getTelnetPort() . ')',
            'set("server.telnet.reverse_dns",false)',
            'set("harbor.bind_addr","0.0.0.0")',
            'set("harbor.reverse_dns",false)',
            '',
            'set("tag.encodings",["UTF-8","ISO-8859-1"])',
            'set("encoder.encoder.export",["artist","title","album","song"])',
            '',
            '# AutoDJ Next Song Script',
            'def azuracast_next_song() =',
            '  uri = get_process_lines("'.$this->_getApiUrlCommand('/api/internal/'.$this->station->getId().'/nextsong').'")',
            '  uri = list.hd(uri, default="")',
            '  log("AzuraCast Raw Response: #{uri}")',
            '  request.create(uri)',
            'end',
            '',
            '# DJ Authentication',
            'def dj_auth(user,password) =',
            '  log("Authenticating DJ: #{user}")',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand('/api/internal/'.$this->station->getId().'/auth', ['dj_user' => '#{user}', 'dj_password' => '#{password}']).'")',
            '  ret = list.hd(ret, default="")',
            '  log("AzuraCast DJ Auth Response: #{ret}")',
            '  bool_of_string(ret)',
            'end',
            '',
            'live_enabled = ref false',
            '',
            'def live_connected(header) =',
            '  log("DJ Source connected! #{header}")',
            '  live_enabled := true',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand('/api/internal/'.$this->station->getId().'/djon').'")',
            'end',
            '',
            'def live_disconnected() =',
            '  log("DJ Source disconnected!")',
            '  live_enabled := false',
            '  ret = get_process_lines("'.$this->_getApiUrlCommand('/api/internal/'.$this->station->getId().'/djoff').'")',
            'end',
            '',
        ];

        // Clear out existing playlists directory.
        $current_playlists = array_diff(scandir($playlist_path, SCANDIR_SORT_NONE), ['..', '.']);
        foreach ($current_playlists as $list) {
            @unlink($playlist_path . '/' . $list);
        }

        // Set up playlists using older format as a fallback.
        $ls_config[] = '# Fallback Playlists';

        $has_default_playlist = false;
        $playlist_objects = [];

        foreach ($this->station->getPlaylists() as $playlist_raw) {
            /** @var \Entity\StationPlaylist $playlist_raw */
            if (!$playlist_raw->getIsEnabled()) {
                continue;
            }
            if ($playlist_raw->getType() === 'default') {
                $has_default_playlist = true;
            }

            $playlist_objects[] = $playlist_raw;
        }

        // Create a new default playlist if one doesn't exist.
        if (!$has_default_playlist) {

            $this->logger->info('No default playlist existed for this station; new one was automatically created.', ['station_id' => $this->station->getId(), 'station_name' => $this->station->getName()]);

            // Auto-create an empty default playlist.
            $default_playlist = new \Entity\StationPlaylist($this->station);
            $default_playlist->setName('default');

            /** @var EntityManager $em */
            $this->em->persist($default_playlist);
            $this->em->flush();

            $playlist_objects[] = $default_playlist;
        }

        $playlist_weights = [];
        $playlist_vars = [];

        foreach ($playlist_objects as $playlist) {

            $playlist_file_contents = $playlist->export('m3u', true);

            $playlist_var_name = 'playlist_' . $playlist->getShortName();
            $playlist_file_path =  $playlist_path . '/' . $playlist_var_name . '.m3u';

            file_put_contents($playlist_file_path, $playlist_file_contents);

            $ls_config[] = $playlist_var_name . ' = playlist(reload_mode="watch","' . $playlist_file_path . '")';

            if ($playlist->getType() === 'default') {
                $playlist_weights[] = $playlist->getWeight();
                $playlist_vars[] = $playlist_var_name;
            }
            if ($playlist->getType() === 'custom') {
                $ls_config[] = 'ignore('.$playlist_var_name.')';
            }
        }

        $ls_config[] = '';

        // Create fallback playlist based on all default playlists.
        $ls_config[] = 'playlists = random(weights=[' . implode(', ', $playlist_weights) . '], [' . implode(', ',
                $playlist_vars) . ']);';

        $ls_config[] = 'dynamic = request.dynamic(id="azuracast_next_song", azuracast_next_song)';
        $ls_config[] = 'dynamic = cue_cut(id="azuracast_next_song_cued", dynamic)';
        $ls_config[] = 'radio = fallback(track_sensitive = false, [dynamic, playlists, blank(duration=2.)])';
        $ls_config[] = '';

        // Add harbor (live DJ input) source.
        $harbor_params = [
            '"/"',
            'port='.$this->getStreamPort(),
            'user="shoutcast"',
            'auth=dj_auth',
            'icy=true',
            'max=30.',
            'buffer=10.',
            'icy_metadata_charset="'.$charset.'"',
            'metadata_charset="'.$charset.'"',
            'on_connect=live_connected',
            'on_disconnect=live_disconnected',
        ];

        $ls_config[] = 'live = audio_to_stereo(input.harbor('.implode(', ', $harbor_params).'))';
        $ls_config[] = 'ignore(output.dummy(live, fallible=true))';
        $ls_config[] = 'live = fallback(track_sensitive=false, [live, blank(duration=2.)])';

        $ls_config[] = '';
        $ls_config[] = 'radio = switch(id="live_switch", track_sensitive=false, [({!live_enabled}, live), ({true}, radio)])';
        $ls_config[] = '';

        // Crossfading
        $crossfade = (int)($settings['crossfade'] ?? 2);
        if ($crossfade > 0) {
            $start_next = round($crossfade * 1.5);
            $ls_config[] = '# Crossfading';
            $ls_config[] = 'radio = crossfade(start_next=' . $start_next . '.,fade_out=' . $crossfade . '.,fade_in=' . $crossfade . '.,radio)';
            $ls_config[] = '';
        }

        // Custom configuration

        if (!empty($settings['custom_config'])) {
            $ls_config[] = '# Custom Configuration (Specified in Station Profile)';
            $ls_config[] = $settings['custom_config'];
            $ls_config[] = '';
        }

        $ls_config[] = '# Outbound Broadcast';

        // Configure the outbound broadcast.
        $fe_settings = (array)$this->station->getFrontendConfig();

        // Reset the incrementing counter for stream IDs.
        $this->output_stream_number = 0;

        switch ($this->station->getFrontendType()) {
            case 'remote':
                $mounts = $this->station->getMounts();

                if ($mounts->count() > 0) {
                    foreach ($this->station->getMounts() as $mount_row) {
                        /** @var Entity\StationMount $mount_row */
                        if (!$mount_row->getEnableAutodj()) {
                            continue;
                        }

                        $stream_mount = NULL;
                        $stream_username = $mount_row->getRemoteSourceUsername();
                        $stream_password = $mount_row->getRemoteSourcePassword();

                        switch($mount_row->getRemoteType())
                        {
                            case 'shoutcast2':
                                $stream_password .= ':#'.$mount_row->getRemoteMount();
                                break;

                            case 'icecast':
                                $stream_mount = $mount_row->getRemoteMount();
                                break;
                        }

                        $remote_url_parts = parse_url($mount_row->getRemoteUrl());

                        $ls_config[] = $this->_getOutputString(
                            $remote_url_parts['host'],
                            $remote_url_parts['port'],
                            $stream_mount,
                            $stream_username,
                            $stream_password,
                            strtolower($mount_row->getAutodjFormat() ?: 'mp3'),
                            $mount_row->getAutodjBitrate() ?: 128,
                            $charset,
                            false,
                            $mount_row->getRemoteType() !== 'icecast'
                        );
                    }


                } else {
                    $this->logger->error('Mount Points feature was not used to configure remote broadcasting; cannot continue.', ['station_id' => $this->station->getId(), 'station_name' => $this->station->getName()]);
                    return false;
                }
                break;

            case 'shoutcast2':
                $i = 0;
                foreach ($this->station->getMounts() as $mount_row) {
                    /** @var Entity\StationMount $mount_row */
                    $i++;

                    if (!$mount_row->getEnableAutodj()) {
                        continue;
                    }

                    $ls_config[] = $this->_getOutputString(
                        '127.0.0.1',
                        $fe_settings['port'],
                        null,
                        '',
                        $fe_settings['source_pw'].':#'.$i,
                        strtolower($mount_row->getAutodjFormat() ?: 'mp3'),
                        $mount_row->getAutodjBitrate() ?: 128,
                        $charset,
                        $mount_row->getIsPublic(),
                        true
                    );
                }
                break;

            case 'icecast':
            default:
                foreach ($this->station->getMounts() as $mount_row) {
                    /** @var Entity\StationMount $mount_row */
                    if (!$mount_row->getEnableAutodj()) {
                        continue;
                    }

                    $ls_config[] = $this->_getOutputString(
                        '127.0.0.1',
                        $fe_settings['port'],
                        $mount_row->getName(),
                        '',
                        $fe_settings['source_pw'],
                        strtolower($mount_row->getAutodjFormat() ?: 'mp3'),
                        $mount_row->getAutodjBitrate() ?: 128,
                        $charset,
                        $mount_row->getIsPublic(),
                        false
                    );
                }
                break;
        }

        $ls_config_contents = implode("\n", $ls_config);

        $ls_config_path = $config_path . '/liquidsoap.liq';
        file_put_contents($ls_config_path, $ls_config_contents);

        return true;
    }

    /**
     * Returns the URL that LiquidSoap should call when attempting to execute AzuraCast API commands.
     *
     * @param $endpoint
     * @param array $params
     * @return string
     */
    protected function _getApiUrlCommand($endpoint, $params = [])
    {
        $params = (array)$params;
        $params['api_auth'] = $this->station->getAdapterApiKey();

        if (APP_INSIDE_DOCKER) {
            $base_url = 'http://nginx';
        } else {
            $settings_repo = $this->em->getRepository('Entity\Settings');
            $system_base_url = $settings_repo->getSetting('base_url', 'localhost');

            $system_url_port = parse_url($system_base_url, PHP_URL_PORT);
            $base_url = 'http://localhost:'.($system_url_port ?? 80);
        }

        $curl_request = 'curl -s --request POST --url '.$base_url.$endpoint;
        foreach($params as $param_key => $param_val) {
            $curl_request .= ' --form '.$param_key.'='.$param_val;
        }
        return $curl_request;
    }

    /**
     * Filter a user-supplied string to be a valid LiquidSoap config entry.
     *
     * @param $string
     * @return mixed
     */
    protected function _cleanUpString($string)
    {
        return str_replace(['"', "\n", "\r"], ['\'', '', ''], $string);
    }

    /**
     * @var int An incrementing counter used for stream IDs
     */
    protected $output_stream_number = 0;

    /**
     * Given outbound broadcast information, produce a suitable LiquidSoap configuration line for the stream.
     *
     * @param $host
     * @param $port
     * @param $mount
     * @param string $username
     * @param $password
     * @param $format
     * @param $bitrate
     * @param string $encoding "UTF-8" or "ISO-8859-1"
     * @param bool $is_public
     * @param bool $shoutcast_mode
     * @return string
     */
    protected function _getOutputString($host, $port, $mount, $username = '', $password, $format, $bitrate, $encoding = 'UTF-8', $is_public = false, $shoutcast_mode = false)
    {
        $this->output_stream_number++;

        switch($format) {
            case 'aac':
                $output_format = '%fdkaac(channels=2, samplerate=44100, bitrate='.(int)$bitrate.', afterburner=true, aot="mpeg4_he_aac_v2", transmux="adts", sbr_mode=true)';
                break;

            case 'ogg':
                $output_format = '%vorbis.cbr(samplerate=44100, channels=2, bitrate=' . (int)$bitrate . ')';
                break;

            case 'opus':
                $output_format = '%opus(bitrate='.(int)$bitrate.', vbr="none", application="audio", channels=2, signal="music")';
                break;

            case 'mp3':
            default:
                $output_format = '%mp3(samplerate=44100,stereo=true,bitrate=' . (int)$bitrate . ', id3v2=true)';
                break;
        }

        $output_params = [];
        $output_params[] = $output_format;
        $output_params[] = 'id="radio_out_' . $this->output_stream_number . '"';

        $output_params[] = 'host = "'.str_replace('"', '', $host).'"';
        $output_params[] = 'port = ' . (int)$port;
        if (!empty($username)) {
            $output_params[] = 'username = "'.str_replace('"', '', $username).'"';
        }
        $output_params[] = 'password = "'.str_replace('"', '', $password).'"';
        if (!empty($mount)) {
            $output_params[] = 'mount = "'.$mount.'"';
        }

        $output_params[] = 'name = "' . $this->_cleanUpString($this->station->getName()) . '"';
        $output_params[] = 'description = "' . $this->_cleanUpString($this->station->getDescription()) . '"';

        if (!empty($this->station->getUrl())) {
            $output_params[] = 'url = "' . $this->_cleanUpString($this->station->getUrl()) . '"';
        }

        $output_params[] = 'public = '.($is_public ? 'true' : 'false');
        $output_params[] = 'encoding = "'.$encoding.'"';

        if ($shoutcast_mode) {
            $output_params[] = 'protocol="icy"';
        }

        $output_params[] = 'radio';

        return 'output.icecast(' . implode(', ', $output_params) . ')';
    }

    /**
     * @inheritdoc
     */
    public function getCommand()
    {
        if ($binary = self::getBinary()) {
            $config_path = $this->station->getRadioConfigDir() . '/liquidsoap.liq';
            return $binary . ' ' . $config_path;
        }

        return '/bin/false';
    }

    /**
     * Tell LiquidSoap to skip the currently playing song.
     *
     * @return array
     * @throws \App\Exception
     */
    public function skip()
    {
        return $this->command('radio_out_1.skip');
    }

    /**
     * Execute the specified remote command on LiquidSoap via the telnet API.
     *
     * @param $command_str
     * @return array
     * @throws \App\Exception
     */
    public function command($command_str)
    {
        $fp = stream_socket_client('tcp://'.(APP_INSIDE_DOCKER ? 'stations' : 'localhost').':' . $this->_getTelnetPort(), $errno, $errstr, 20);

        if (!$fp) {
            throw new \App\Exception('Telnet failure: ' . $errstr . ' (' . $errno . ')');
        }

        fwrite($fp, str_replace(["\\'", '&amp;'], ["'", '&'], urldecode($command_str)) . "\nquit\n");

        $response = [];
        while (!feof($fp)) {
            $response[] = trim(fgets($fp, 1024));
        }

        fclose($fp);

        return $response;
    }

    /**
     * Returns the port used for DJs/Streamers to connect to LiquidSoap for broadcasting.
     *
     * @return int The port number to use for this station.
     */
    public function getStreamPort(): int
    {
        $settings = (array)$this->station->getBackendConfig();

        if (!empty($settings['dj_port'])) {
            return (int)$settings['dj_port'];
        }

        // Default to frontend port + 5
        $frontend_config = (array)$this->station->getFrontendConfig();
        $frontend_port = $frontend_config['port'] ?? (8000 + (($this->station->getId() - 1) * 10));

        return $frontend_port + 5;
    }

    /**
     * Returns the internal port used to relay requests and other changes from AzuraCast to LiquidSoap.
     *
     * @return int The port number to use for this station.
     */
    protected function _getTelnetPort(): int
    {
        $settings = (array)$this->station->getBackendConfig();
        return (int)($settings['telnet_port'] ?? ($this->getStreamPort() - 1));
    }

    /**
     * @inheritdoc
     */
    public static function getBinary()
    {
        $user_base = dirname(APP_INCLUDE_ROOT);
        $new_path = $user_base . '/.opam/system/bin/liquidsoap';

        $legacy_path = '/usr/bin/liquidsoap';

        if (APP_INSIDE_DOCKER || file_exists($new_path)) {
            return $new_path;
        }
        if (file_exists($legacy_path)) {
            return $legacy_path;
        }
        return false;
    }
}