<?php

define('NAME', 'SynoRegRenamer');
define('DESCRIPTION', 'Use regular expressions for renaming files and folders on Synology servers.');

ob_start();

?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="stylesheet" href="css/bootstrap.min.css" />
  <title><?php print NAME; ?></title>
</head>
<body>
<?php

$server = [];
$keys = ['protocol', 'host', 'port'];
$exit = FALSE;

if (isset($_POST['host'])) {
  foreach ($_POST as $key => $value) {
    if (in_array($key, $keys)) {
      setcookie($key, $value);
      $server[$key] = $value;
    }
  }
}
elseif (isset($_GET['action']) && $_GET['action'] === 'exit') {
  $keys[] = 'sid';

  foreach ($keys as $key) {
    setcookie($key);
  }

  $exit = TRUE;
}
else {
  foreach ($keys as $key) {
    $server[$key] = $_COOKIE[$key];
  }
}

function request($script, $api, $version, $method, $arguments, $timeout = 10) {
  global $server;

  $response = new stdClass();

  $query = [
      'api' => 'SYNO.' . $api,
      'version' => $version,
      'method' => $method,
    ] + $arguments;

  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, $server['protocol'] . '://' . $server['host'] . ':' . $server['port'] . '/webapi/' . $script . '.cgi?' . http_build_query($query));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

  $response->content = curl_exec($ch);
  $response->error = curl_errno($ch);

  if ($response->error) {
    $response->content = curl_error($ch);
  }

  curl_close($ch);

  return $response;
}

function alert($message, $type = 'danger') { ?>

<div class="alert alert-<?php print $type; ?> alert-dismissible fade show" role="alert">
  <?php print $message; ?>
  <button type="button" class="close" data-dismiss="alert" aria-label="Close">
    <span aria-hidden="true">&times;</span>
  </button>
</div>

<?php }

$sid = NULL;

if (!isset($_COOKIE['sid']) && isset($_POST['host'])) {
  $response = request('auth', 'API.Auth', 3, 'login', [
    'account' => $_POST['username'],
    'passwd' => $_POST['password'],
    'session' => 'FileStation',
    'format' => 'sid',
  ]);

  if ($response->error) {
    alert($response->error . ': ' . $response->content);
  }
  else {
    $response = json_decode($response->content);

    if ($response->success) {
      setcookie('sid', $sid = $response->data->sid);
    }
    else {
      alert(var_export($response, TRUE));
    }
  }
}
elseif (!$exit) {
  $sid = $_COOKIE['sid'];
}

?>


<nav class="navbar navbar-expand-lg <?php if ($sid): ?>navbar-light bg-light<?php else: ?>navbar-dark bg-dark<?php endif; ?> shadow mb-4">
  <div class="container">
    <a href="/" class="navbar-brand"><?php print NAME; ?></a>
    <?php if ($sid): ?>
      <ul class="navbar-nav">
        <li class="nav-item">
          <a href="?action=exit" class="nav-link">Sign out</a>
        </li>
      </ul>
    <?php endif; ?>
  </div>
</nav>

<div class="container">

<?php if (!$sid): ?>
  <div class="jumbotron mb-4 d-none d-sm-block">
    <h1 class="display-4"><?php print NAME; ?></h1>
    <p class="lead mb-0"><?php print DESCRIPTION; ?></p>
  </div>

  <div class="jumbotron mt-3 mb-3 pt-3 pb-3 d-block d-sm-none">
    <h1 class="h2"><?php print NAME; ?></h1>
    <p class="lead mb-0"><?php print DESCRIPTION; ?></p>
  </div>

  <form method="post" action="/">
    <div class="card form-group">
      <div class="card-header">Server</div>

      <div class="card-body row">
        <div class="form-group col-sm-2">
          <label for="protocol">Protocol</label>
          <select name="protocol" class="form-control" required>
            <option value="http" selected>HTTP</option>
            <option value="https">HTTPS</option>
          </select>
        </div>

        <div class="form-group col-sm-8">
          <label for="host">Host</label>
          <input type="text" name="host" value="" placeholder="Eg.: myaccount.synology.me" class="form-control" required />
        </div>

        <div class="form-group col-sm-2">
          <label for="port">Port</label>
          <input type="number" name="port" value="5000" min="0" max="65535" class="form-control" required />
        </div>
      </div>
    </div>

    <div class="card form-group">
      <div class="card-header">Account</div>

      <div class="card-body row">
        <div class="form-group col">
          <label for="username">Username</label>
          <input type="text" name="username" value="" class="form-control" required />
        </div>

        <div class="form-group col">
          <label for="password">Password</label>
          <input type="password" name="password" value="" class="form-control" required />
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Connect</button>
  </form>

<?php exit(); ?>

<?php endif; ?>

<?php

$items = [];
$find = FALSE;

if (isset($_GET['path'])) {
  $response = request('entry', 'FileStation.List', 2, 'list', [
    '_sid' => $sid,
    'folder_path' => $_GET['path'],
  ]);

  if ($response->error) {
    alert($response->error . ': ' . $response->content);
    exit();
  }

  $response = json_decode($response->content);

  if (!$response->success) {
    alert($response->error->code);
    exit();
  }

  $pos = strrpos($_GET['path'], '/');
  $url = $pos ? ('?path=' . substr($_GET['path'], 0, $pos)) : '/';

  $items[$url] = [
    'icon' => 'back',
    'name' => 'Back',
  ];

  $replacements = [];
  $can_replace = 0;

  foreach ($response->data->files as $file) {
    $item = [
      'icon' => $file->isdir ? 'folder' : 'file',
      'name' => $file->name,
    ];

    if (isset($_GET['find'])) {
      $new_name = '-';

      if ($item['icon'] === 'file') {
        if ($_GET['action'] === 'find' || $_GET['action'] === 'replace') {
          $new_name = 'Can not rename';

          if (!empty($_GET['replace'])) {
            $name = preg_replace('/' . $_GET['find'] . '/', $_GET['replace'], $item['name']);

            if ($name !== NULL) {
              if (!empty($name) && $name !== $item['name']) {
                $new_name = $name;

                if ($_GET['action'] === 'replace') {
                  $replacements['"' . $file->path . '"'] = '"' . $name . '"';
                }
                else {
                  $can_replace++;
                }
              }
              else {
                $new_name = 'Not changed';
              }
            }
          }
          elseif (preg_match('/' . $_GET['find'] . '/', $item['name']) !== FALSE) {
            $new_name = 'Can rename';
          }
        }
      }
      elseif ($item['icon'] === 'back') {
        $new_name = '';
      }

      if ($new_name) {
        $item['new-name'] = $new_name;
      }
    }

    $items['?path=' . $file->path] = $item;
  }

  if (isset($_GET['find'])) {
    $find = TRUE;

    if ($can_replace) {
      alert($can_replace . ' share element(s) can be renamed.', 'info');
    }

    if (isset($_GET['replace']) && $_GET['action'] === 'replace' && $replacements) {
      $find = FALSE;

      $response = request('entry', 'FileStation.Rename', 2, 'rename', [
        '_sid' => $sid,
        'path' => '[' . implode(',', array_keys($replacements)) . ']',
        'name' => '[' . implode(',', array_values($replacements)) . ']',
      ], 0);

      if ($response->error) {
        alert($response->error . ': ' . $response->content);
      }
      else {
        $response = json_decode($response->content);

        if ($response->success) {
          $files = [];

          foreach ($response->data->files as $file) {
            $files[$file->path] = $file->name;
          }

          $old_items = $items;
          $items = [];

          foreach ($old_items as $path => $item) {
            unset($item['new-name']);

            $new_path = substr($path, strpos($path, '/'));

            if (isset($replacements['"' . $new_path . '"'])) {
              $replacement = substr($replacements['"' . $new_path . '"'], 1, -1);
              $current_path = substr($new_path, 0, strrpos($new_path, '/') + 1) . $replacement;

              if (isset($files[$current_path]) && $files[$current_path] === $replacement) {
                $new_path = $current_path;
                $item['name'] = $replacement;
              }
            }

            $items['?path=' . $new_path] = $item;
          }

          foreach (array_keys($_GET) as $key) {
            if ($key !== 'path') {
              unset($_GET[$key]);
            }
          }

          alert('Successfully renamed ' . count($response->data->files) . ' share element(s).', 'success');
        }
        else {
          $message = '<p>' . $response->error->code;

          if ($response->error->code == 1200) {
            $message .= ': Failed to rename it.';
          }

          $message .= '</p>';

          $errors = [
            408 => 'No such file or directory',
          ];

          foreach ($response->error->errors as $error) {
            $message .= '<p>' . $error->code;

            if (isset($errors[$error->code])) {
              $message .= ': ' . $errors[$error->code] . '.';
            }

            $paths = explode(',', $error->path);

            $message .= '<ol>';

            foreach ($paths as $path) {
              $message .= '<li>' . $path . '</li>';
            }

            $message .= '</ol>';
            $message .= '</p>';
          }

          alert($message);
        }
      }
    }
  }
}
else {
  $response = request('entry', 'FileStation.List', 2, 'list_share', [
    '_sid' => $sid,
    'onlywritable' => TRUE,
  ]);

  if ($response->error) {
    alert($response->error . ': ' . $response->content);
    exit();
  }

  $response = json_decode($response->content);

  if (!$response->success) {
    alert(var_export($response, TRUE));
    exit();
  }

  foreach ($response->data->shares as $share) {
    $items['?path=' . $share->path] = [
      'icon' => 'folder',
      'name' => $share->name,
    ];
  }

}

?>

    <?php if (isset($_GET['path'])): ?>
      <form method="get" action="/" class="form-row">
        <input type="hidden" name="path" value="<?php print $_GET['path']; ?>" />

        <div class="form-group col-5 mb-0">
          <input type="text" name="find" value="<?php print $_GET['find']; ?>" placeholder="Find" class="form-control" required />
        </div>

        <div class="form-group col-4 mb-0">
          <input type="text" name="replace" value="<?php print $_GET['replace']; ?>" placeholder="Replace" class="form-control" />
        </div>

        <div class="form-group col-1 mb-0">
          <button type="submit" name="action" value="find" class="btn btn-primary col">Find</button>
        </div>

        <div class="form-group col-2 mb-0">
          <button type="submit" name="action" value="replace" class="btn btn-primary col">Replace</button>
        </div>
      </form>
    <?php endif; ?>

    <table class="table table-striped table-hover mt-4">
      <thead>
      <tr>
        <th></th>
        <?php if ($find): ?>
          <th class="w-50">Old name</th>
          <th class="w-50">New name</th>
        <?php else: ?>
          <th class="w-100">Name</th>
        <?php endif; ?>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $path => $item): ?>
        <tr>
          <td class="p-2">
            <svg viewBox="0 0 32 32" width="32" height="32" fill="none" stroke="currentcolor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
              <?php if ($item['icon'] === 'folder'): ?>
                <path d="M2 26 L30 26 30 7 14 7 10 4 2 4 Z M30 12 L2 12" />
              <?php elseif ($item['icon'] === 'file'): ?>
                <path d="M6 2 L6 30 26 30 26 10 18 2 Z M18 2 L18 10 26 10" />
              <?php else: ?>
                <path d="M10 6 L2 16 10 26 M2 16 L30 16" />
              <?php endif; ?>
            </svg>
          </td>
          <td>
            <?php if ($item['icon'] === 'file'): ?>
              <?php print $item['name']; ?>
            <?php else: ?>
              <a href="<?php print $path; ?>"><?php print $item['name']; ?></a>
            <?php endif; ?>
          </td>
          <?php if ($find): ?>
            <td><?php print $item['new-name']; ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

  </div>

  <script src="js/jquery-3.3.1.slim.min.js"></script>
  <script src="js/popper.min.js"></script>
  <script src="js/bootstrap.min.js"></script>
</body>
</html>

<?php ob_end_flush(); ?>
