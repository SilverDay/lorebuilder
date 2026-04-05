# /project:new-endpoint
# Usage: /project:new-endpoint [METHOD] [/path] [controller::method] [minRole]
# Example: /project:new-endpoint POST /worlds/{wid}/entities EntityController::create author

Create a new API endpoint for LoreBuilder following the project's security patterns.

## Steps

1. **Add route** to public/index.php router registration block:
   ```php
   $router->METHOD('/api/v1/PATH', [ControllerClass::class, 'method']);
   ```

2. **Create or update controller** in api/ControllerClass.php:
   ```php
   <?php declare(strict_types=1);

   class EntityController
   {
       public function create(): void
       {
           // Step 1: Auth
           Auth::requireSession();
           
           // Step 2: Extract + validate route params
           $worldId = (int) Router::param('wid');
           
           // Step 3: Authorisation
           Guard::requireWorldAccess($worldId, Auth::userId(), minRole: 'author');
           
           // Step 4: Rate limit (AI endpoints only)
           // RateLimit::check("ai:user:{Auth::userId()}", 20, 3600);
           
           // Step 5: Validate input
           $data = Validator::parse($_POST, [
               'name'   => ['required', 'string', 'max:255'],
               'type'   => ['required', 'in:Character,Location,...'],
               'status' => ['in:draft,published', 'default:draft'],
           ]);
           
           // Step 6: Business logic + DB (prepared statements only)
           $id = DB::execute(
               "INSERT INTO entities (world_id, created_by, type, name, status) 
                VALUES (:wid, :uid, :type, :name, :status)",
               [':wid' => $worldId, ':uid' => Auth::userId(),
                ':type' => $data['type'], ':name' => $data['name'], ':status' => $data['status']]
           );
           
           // Step 7: Audit log
           AuditLogger::log('entity.create', 'entity', $id, $worldId, Auth::userId());
           
           // Step 8: Response
           http_response_code(201);
           echo json_encode(['data' => ['id' => $id]]);
       }
   }
   ```

3. **Run security review**: /project:security-review

4. **Write test** (if test suite exists): scripts/test-endpoint.php METHOD /path

Do NOT proceed to step 3 until step 2 is complete and security review passes.
