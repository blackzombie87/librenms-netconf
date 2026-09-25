{{-- admin rename / notes, shared by both overview renderings --}}
@if ($can_admin)
    <form method="post" action="{{ route('netconf.fabric.update', $fabric['id']) }}" class="form-horizontal">
        @csrf
        <div class="form-group">
            <label class="col-sm-3 control-label" for="fabric-name">Name</label>
            <div class="col-sm-9"><input type="text" id="fabric-name" name="name" class="form-control input-sm" maxlength="64" value="{{ old('name', $fabric['name']) }}" required></div>
        </div>
        <div class="form-group">
            <label class="col-sm-3 control-label" for="fabric-notes">Notes</label>
            <div class="col-sm-9"><textarea id="fabric-notes" name="notes" class="form-control input-sm" rows="2" maxlength="2000">{{ old('notes', $fabric['notes']) }}</textarea></div>
        </div>
        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-9"><button type="submit" class="btn btn-primary btn-sm">Save</button> <small class="text-muted">The key and the members stay automatic.</small></div>
        </div>
    </form>
@endif
