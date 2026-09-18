@extends('layouts.librenmsv1')

@section('title', 'NETCONF definitions')

@section('content')
<div class="container-fluid">
    <div class="panel panel-default">
        <div class="panel-heading"><i class="fa fa-file-code-o fa-fw" aria-hidden="true"></i> <strong>NETCONF definitions</strong>
            <span class="pull-right"><a href="{{ route('netconf.status') }}">status</a></span>
        </div>
        <div class="panel-body">
            <p class="text-muted">Loaded from: @foreach ($directories as $dir)<code>{{ $dir }}</code> @endforeach — later directories override earlier ones by name.</p>
            @foreach ($errors as $error)
                <div class="alert alert-danger">{{ $error }}</div>
            @endforeach
            <table class="table table-condensed table-hover">
                <thead><tr><th>Name</th><th>Description</th><th>Match</th><th>Commands</th><th>Sensors</th><th>Ports</th><th>Metrics</th><th>Source</th></tr></thead>
                <tbody>
                @foreach ($definitions as $definition)
                    <tr class="{{ $definition->enabled ? '' : 'text-muted' }}">
                        <td><code>{{ $definition->name }}</code></td>
                        <td>{{ $definition->description }}</td>
                        <td>@foreach ($definition->match->describe() as $k => $v)<small><code>{{ $k }}={{ $v }}</code></small> @endforeach</td>
                        <td>
                            @foreach ($definition->commands as $command)
                                <small><code>{{ $command->label() }}</code>{{ $command->optional ? ' (optional)' : '' }}{{ $command->every > 1 ? ' every ' . $command->every : '' }}</small><br>
                            @endforeach
                        </td>
                        <td>{{ count($definition->sensors) }}</td>
                        <td>{{ count($definition->ports) }}</td>
                        <td>{{ count($definition->metrics) }}</td>
                        <td><small>{{ basename(dirname($definition->source)) }}/{{ basename($definition->source) }}</small></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
